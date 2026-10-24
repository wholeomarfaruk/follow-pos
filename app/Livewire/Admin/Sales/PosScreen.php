<?php

namespace App\Livewire\Admin\Sales;

use App\Actions\Accounts\PostOrderPayment;
use App\Actions\Sales\PackOrderItem;
use App\Concerns\CreatesMasterProfile;
use App\Enums\PaymentMethod;
use App\Enums\Sales\OrderPaymentType;
use App\Enums\Sales\OrderStatus;
use App\Enums\Sales\PaymentStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Sales\CouponNotApplicableException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\InventoryBatch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\Warehouse;
use App\Services\CouponShippingService;
use App\Services\Profiles\LedgerAccounts;
use App\Services\Shipping\ShippingCalculator;
use App\Services\Shipping\ShippingQuote;
use App\Services\StockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Walk-in sale: the goods leave the counter, so the order is created
 * Delivered — stock taken (oldest batches first, recorded as packed when the
 * Inventory module is on) and the payment booked into the chosen cash / bank
 * account, the same way Order Detail books one. Accounts then posts the sale
 * + COGS from Posting Pending › Orders (or the hourly auto-post) like any
 * other delivered order. A sale with no customer goes to the shared walk-in
 * customer, since Accounts can only book a sale against a customer.
 */
class PosScreen extends Component
{
    use CreatesMasterProfile;

    /** What the POS method picker offers (value => label), from the shared PaymentMethod list. */
    public static function paymentMethods(): array
    {
        return PaymentMethod::options([
            PaymentMethod::CASH, PaymentMethod::BKASH, PaymentMethod::NAGAD, PaymentMethod::ROCKET, PaymentMethod::CARD, PaymentMethod::BANK,
        ]);
    }

    public const WALK_IN_CODE = 'CUS-WALKIN';

    public ?PosSession $session = null;

    public string $productSearch = '';

    /** @var array<int, array{product_id: string, variant_id: string, label: string, image_url: string|null, quantity: string, unit_price: string, purchase_price: string}> */
    public array $items = [];

    public string $customerId = '';
    public string $couponCode = '';

    public float  $shippingDiscount = 0.0;
    public string $couponError      = '';

    public string $discountAmount = '';

    /**
     * Set when the cart needs units that Pending orders are waiting for —
     * the cashier ticks "Sell anyway" to take them (sellHeldStock).
     */
    public bool $heldWarning = false;

    public bool $sellHeldStock = false;

    public string $paymentMethod  = PaymentMethod::CASH->value;
    public string $amountTendered = '';

    /** Accounts module on: the cash / bank / MFS account the money went into. */
    public string $paymentAccountId = '';

    /**
     * 'pickup' — the customer takes it from the counter (order Delivered now);
     * 'delivery' — we send it: order Confirmed with a delivery charge, then
     * packed and booked with a courier from Orders like any other order.
     */
    public string $fulfilment = 'pickup';

    public string $shippingAddressId = '';
    public string $newAddress        = '';
    public string $shippingMethodId  = '';
    public string $deliveryCharge    = '';

    // quick customer registration
    public bool   $newCustomerModal    = false;
    public string $newCustomerName     = '';
    public string $newCustomerPhone    = '';
    public string $newCustomerEmail    = '';

    public function mount(): void
    {
        $this->session = PosSession::where('user_id', auth()->id())->open()->latest('id')->first();
        $this->paymentAccountId = $this->defaultAccountFor($this->paymentMethod);
    }

    public function updatedPaymentMethod(): void
    {
        $this->paymentAccountId = $this->defaultAccountFor($this->paymentMethod);
    }

    protected function accountsOn(): bool
    {
        return (bool) Setting::get('accounts_enabled', false, 'modules');
    }

    /** @return array<int, array{id: int, name: string, subtype: string}> */
    protected function cashAccounts(): array
    {
        return $this->accountsOn() ? app(LedgerAccounts::class)->cashAccounts() : [];
    }

    /** Cash → a cash account, Card / Bank → a bank account, bKash / Nagad / Rocket → the MFS account named after it (else any MFS). */
    protected function defaultAccountFor(string $method): string
    {
        return (string) (PaymentMethod::tryFrom($method)?->pickAccount($this->cashAccounts()) ?? '');
    }

    /** The one shared customer a no-name POS sale is booked against. */
    protected function walkInCustomer(): Customer
    {
        if ($existing = Customer::withTrashed()->where('customer_code', self::WALK_IN_CODE)->first()) {
            // Deleted by hand at some point — bring it back, a sale can't be booked against a trashed customer.
            $existing->trashed() && $existing->restore();

            return $existing;
        }

        try {
            return DB::transaction(function () {
                $profile = $this->createMasterProfileFor(['display_name' => 'Walk-in Customer', 'first_name' => 'Walk-in', 'last_name' => 'Customer']);

                return Customer::create([
                    'master_profile_id' => $profile->id,
                    'customer_code'     => self::WALK_IN_CODE,
                    'first_name'        => 'Walk-in',
                    'last_name'         => 'Customer',
                    'full_name'         => 'Walk-in Customer',
                    'phone'             => '',
                    'status'            => 'active',
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Another till made it a moment ago (unique customer_code).
            return Customer::where('customer_code', self::WALK_IN_CODE)->firstOrFail();
        }
    }

    public function addProductItem(int $productId): void
    {
        $product = Product::active()->find($productId);

        if (! $product) {
            return;
        }

        $this->items[] = [
            'product_id'     => (string) $product->id,
            'variant_id'     => '',
            'label'          => $product->name,
            'image_url'      => $product->featured_image_id ? file_path($product->featured_image_id) : null,
            'quantity'       => '1',
            'unit_price'     => (string) $product->sellingPrice(), // Product::sellingPrice(): min of regular/sale
            'purchase_price' => (string) ($product->purchase_price ?? ''),
        ];

        $this->productSearch = '';
    }

    public function selectVariantForItem(int $index, ?int $variantId): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $product = Product::find($this->items[$index]['product_id']);

        if (! $variantId) {
            $this->items[$index]['variant_id'] = '';
            $this->items[$index]['image_url']  = $product?->featured_image_id ? file_path($product->featured_image_id) : null;
            return;
        }

        $variant = ProductVariant::active()->with(['media' => fn ($q) => $q->where('is_primary', true)])->find($variantId);

        if (! $variant) {
            return;
        }

        $variantImage = $variant->media->first();

        $this->items[$index]['variant_id']     = (string) $variant->id;
        $this->items[$index]['unit_price']     = (string) $variant->product->sellingPrice($variant);
        $this->items[$index]['purchase_price'] = (string) ($variant->purchase_price ?? $this->items[$index]['purchase_price']);
        $this->items[$index]['image_url']      = $variantImage
            ? file_path($variantImage->media_id)
            : ($product?->featured_image_id ? file_path($product->featured_image_id) : null);
    }

    public function incrementQuantity(int $index): void
    {
        if (isset($this->items[$index])) {
            $this->items[$index]['quantity'] = (string) ((float) $this->items[$index]['quantity'] + 1);
        }
    }

    public function decrementQuantity(int $index): void
    {
        if (isset($this->items[$index])) {
            $qty = (float) $this->items[$index]['quantity'] - 1;
            $this->items[$index]['quantity'] = (string) max(1, $qty);
        }
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    /**
     * Computes the minimum allowed selling price for a purchase price, based
     * on the site-wide minimum margin percentage (Settings > Pricing).
     * Returns null when there's no purchase price to protect against.
     */
    public function minAllowedPrice(float $purchasePrice): ?float
    {
        if ($purchasePrice <= 0) {
            return null;
        }

        $minMarginPercent = (float) Setting::get('min_margin_percent', '15', 'pricing');

        return round($purchasePrice * (1 + $minMarginPercent / 100), 2);
    }

    /**
     * Hard-enforces the minimum margin over purchase price whenever a
     * cashier edits the unit price directly: prices below the minimum are
     * snapped back up to the minimum rather than accepted, and the cashier
     * is told why. The field cannot go below purchase price + margin%.
     */
    public function updatedItems($value, $key): void
    {
        [$index, $field] = explode('.', $key) + [null, null];

        if ($field !== 'unit_price' || $index === null) {
            return;
        }

        $item = $this->items[$index];
        $purchasePrice = (float) ($item['purchase_price'] ?? 0);
        $unitPrice     = (float) ($item['unit_price'] ?? 0);

        $minAllowedPrice = $this->minAllowedPrice($purchasePrice);

        if ($minAllowedPrice !== null && $unitPrice < $minAllowedPrice) {
            $this->items[$index]['unit_price'] = (string) $minAllowedPrice;

            $this->dispatch('toast', [
                'type'    => 'warning',
                'message' => sprintf(
                    'Price cannot go below the minimum margin — set to %.2f (purchase %.2f + %.0f%%).',
                    $minAllowedPrice,
                    $purchasePrice,
                    (float) Setting::get('min_margin_percent', '15', 'pricing')
                ),
            ]);
        }
    }

    public function getItemsSubtotalProperty(): float
    {
        return collect($this->items)->sum(fn ($item) => (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0));
    }

    public function getDiscountAmountClampedProperty(): float
    {
        return max(0.0, min((float) ($this->discountAmount ?: 0), $this->itemsSubtotal));
    }

    public function updatedDiscountAmount(): void
    {
        $this->validateOnly('discountAmount', [
            'discountAmount' => ['nullable', 'numeric', 'min:0', 'max:' . $this->itemsSubtotal],
        ], [
            'discountAmount.max' => 'Discount cannot exceed the subtotal (' . number_format($this->itemsSubtotal, 2) . ').',
        ]);
    }

    public function getGrandTotalProperty(): float
    {
        return max(0.0, $this->itemsSubtotal - $this->discountAmountClamped + $this->deliveryAmount - $this->shippingDiscount);
    }

    public function getChangeDueProperty(): float
    {
        return max(0.0, (float) ($this->amountTendered ?: 0) - $this->grandTotal);
    }

    /** Delivery: what's paid now (tendered, up to the total); the rest is collected on delivery (COD). */
    public function getPaidNowProperty(): float
    {
        return round(min((float) ($this->amountTendered ?: 0), $this->grandTotal), 2);
    }

    public function getDeliveryAmountProperty(): float
    {
        return $this->isDelivery() ? max(0.0, (float) ($this->deliveryCharge ?: 0)) : 0.0;
    }

    protected function isDelivery(): bool
    {
        return $this->fulfilment === 'delivery';
    }

    public function updatedCustomerId(): void
    {
        $this->shippingAddressId = $this->defaultAddressId();
        $this->newAddress = '';

        if ($this->couponCode !== '') {
            $this->applyCoupon();
        }
    }

    public function updatedFulfilment(): void
    {
        $this->fulfilment = $this->fulfilment === 'delivery' ? 'delivery' : 'pickup';
        $this->resetErrorBag();

        if ($this->isDelivery()) {
            $this->shippingAddressId = $this->shippingAddressId ?: $this->defaultAddressId();
            if ($this->shippingMethodId === '') {
                $this->shippingMethodId = $this->defaultMethodId();
                $this->deliveryCharge = $this->quoteCharge();
            }
        }

        if ($this->couponCode !== '') {
            $this->applyCoupon();
        }
    }

    /** Picking a method fills the charge from its rates (Settings › Shipping); the cashier can still change it. */
    public function updatedShippingMethodId(): void
    {
        $this->deliveryCharge = $this->quoteCharge();

        if ($this->couponCode !== '') {
            $this->applyCoupon();
        }
    }

    public function updatedDeliveryCharge(): void
    {
        if ($this->couponCode !== '') {
            $this->applyCoupon();
        }
    }

    /** @return array<int, string> method id => "Zone · Method (time)" */
    protected function shippingMethods(): array
    {
        return app(ShippingCalculator::class)->zones()
            ->flatMap(fn ($zone) => $zone->methods->mapWithKeys(fn ($m) => [$m->id => $zone->name . ' · ' . $m->name . ($m->delivery_time ? " ({$m->delivery_time})" : '')]))
            ->all();
    }

    /** The default zone's first method, else the first one there is. */
    protected function defaultMethodId(): string
    {
        $zones = app(ShippingCalculator::class)->zones();
        $zone = $zones->firstWhere('is_default', true) ?? $zones->first();

        return (string) ($zone?->methods->first()?->id ?? '');
    }

    /** The chosen method's charge for this cart, as the storefront would quote it. */
    protected function quoteCharge(): string
    {
        $method = $this->shippingMethodId !== '' ? ShippingMethod::where('is_active', true)->find($this->shippingMethodId) : null;

        return $method ? (string) $this->quote($method)->amount : '';
    }

    protected function quote(ShippingMethod $method): ShippingQuote
    {
        $lines = collect($this->items)->map(fn ($i) => [
            'product' => $i['product_id'] ? Product::find($i['product_id']) : null,
            'quantity' => (float) ($i['quantity'] ?? 0),
            'is_gift' => false,
        ])->all();

        return app(ShippingCalculator::class)->quote($method, $lines, $this->itemsSubtotal);
    }

    /** @return \Illuminate\Support\Collection<int, DeliveryAddress> */
    protected function customerAddresses()
    {
        return $this->customerId
            ? DeliveryAddress::where('customer_id', $this->customerId)->where('is_active', true)
                ->orderByDesc('is_default_shipping')->orderByDesc('id')->get()
            : collect();
    }

    protected function defaultAddressId(): string
    {
        return (string) ($this->customerAddresses()->first()?->id ?? '');
    }

    public function openNewCustomerModal(): void
    {
        $this->reset(['newCustomerName', 'newCustomerPhone', 'newCustomerEmail']);
        $this->resetValidation();
        $this->newCustomerModal = true;
    }

    public function createCustomer(): void
    {
        $this->validate([
            'newCustomerName'  => 'required|string|max:150',
            'newCustomerPhone' => 'required|string|max:20',
            'newCustomerEmail' => 'nullable|email|max:150',
        ]);

        $code = 'CUS-' . str_pad((string) (Customer::withTrashed()->max('id') + 1), 5, '0', STR_PAD_LEFT);

        [$firstName, $lastName] = array_pad(explode(' ', trim($this->newCustomerName), 2), 2, null);

        $masterProfile = $this->createMasterProfileFor([
            'display_name' => $this->newCustomerName,
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'phone'        => $this->newCustomerPhone,
            'email'        => $this->newCustomerEmail ?: null,
        ]);

        $customer = Customer::create([
            'master_profile_id' => $masterProfile->id,
            'customer_code' => $code,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'full_name'     => $this->newCustomerName,
            'phone'         => $this->newCustomerPhone,
            'email'         => $this->newCustomerEmail ?: null,
            'status'        => 'active',
        ]);

        activity('customers')
            ->causedBy(auth()->user())
            ->performedOn($customer)
            ->event('created')
            ->log("Customer \"{$customer->full_name}\" was added");

        $this->customerId       = (string) $customer->id;
        $this->newCustomerModal = false;

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Customer registered and selected']);
    }

    public function applyCoupon(): void
    {
        $this->couponError = '';

        if ($this->couponCode === '') {
            $this->shippingDiscount = 0.0;
            return;
        }

        // Coupons are free-delivery coupons — they discount the delivery charge, so a counter pickup has nothing to take off.
        if (! $this->isDelivery()) {
            $this->shippingDiscount = 0.0;
            $this->couponError = 'Free-delivery coupon — শুধু home delivery-তে চলে।';
            return;
        }

        $customer = $this->customerId ? Customer::find($this->customerId) : null;

        try {
            $coupon = app(CouponShippingService::class)->validate($this->couponCode, $this->itemsSubtotal, $customer);

            $this->shippingDiscount = app(CouponShippingService::class)->calculateShippingDiscount($coupon, $this->deliveryAmount);

            if ($this->shippingDiscount <= 0) {
                $this->couponError = 'This coupon does not offer a discount for this sale.';
            }
        } catch (CouponNotApplicableException $e) {
            $this->shippingDiscount = 0.0;
            $this->couponError = $e->getMessage();
        }
    }

    public function updatedCouponCode(): void
    {
        $this->applyCoupon();
    }

    public function removeCoupon(): void
    {
        $this->couponCode       = '';
        $this->shippingDiscount = 0.0;
        $this->couponError      = '';
    }

    public function completeSale(): void
    {
        if (! $this->session) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'No open session. Please open a session first.']);
            return;
        }

        $this->validate([
            'items'              => 'required|array|min:1',
            'items.*.quantity'   => 'required|numeric|min:0.001',
            'items.*.unit_price' => 'required|numeric|min:0',
            'discountAmount'     => ['nullable', 'numeric', 'min:0', 'max:' . $this->itemsSubtotal],
            'paymentMethod'      => ['required', Rule::in(array_keys(self::paymentMethods()))],
            'paymentAccountId'   => $this->accountsOn() ? ['required', Rule::in(array_column($this->cashAccounts(), 'id'))] : ['nullable'],
            'amountTendered'     => 'required|numeric|min:0',
        ] + ($this->isDelivery() ? [
            'customerId'        => 'required|exists:customers,id',
            'shippingAddressId' => ['nullable', 'required_without:newAddress', Rule::exists('delivery_addresses', 'id')->where('customer_id', (int) $this->customerId)],
            'newAddress'        => 'nullable|required_without:shippingAddressId|string|max:1000',
            'shippingMethodId'  => ['nullable', Rule::exists('shipping_methods', 'id')->where('is_active', true)],
            'deliveryCharge'    => 'required|numeric|min:0',
        ] : []), [
            'discountAmount.max' => 'Discount cannot exceed the subtotal (' . number_format($this->itemsSubtotal, 2) . ').',
            'paymentAccountId.required' => 'Select the account this money went into.',
            'customerId.required' => 'Home delivery-র জন্য customer বেছে নিন (নাম, phone লাগবে)।',
            'shippingAddressId.required_without' => 'Delivery address বেছে নিন বা নতুন লিখুন।',
            'newAddress.required_without' => 'Delivery address বেছে নিন বা নতুন লিখুন।',
        ]);

        foreach ($this->items as $index => $item) {
            $minAllowedPrice = $this->minAllowedPrice((float) ($item['purchase_price'] ?? 0));

            if ($minAllowedPrice !== null && (float) $item['unit_price'] < $minAllowedPrice) {
                $this->addError(
                    "items.{$index}.unit_price",
                    sprintf('Price for "%s" is below the minimum margin (min %.2f).', $item['label'], $minAllowedPrice)
                );
                return;
            }
        }

        // A delivery can be paid in part (or not at all) now — the rest is collected on delivery.
        if (! $this->isDelivery() && (float) $this->amountTendered < $this->grandTotal) {
            $this->addError('amountTendered', 'Amount tendered is less than the total due.');
            return;
        }

        $warehouse = $this->session->register?->branch_id
            ? Warehouse::where('branch_id', $this->session->register->branch_id)->first()
            : null;
        $warehouse ??= Warehouse::default();

        $stockService = app(StockService::class);

        // Units Pending orders are waiting for (not taken from stock until
        // they're confirmed) — selling them needs the cashier's say-so.
        $held = $stockService->heldByPendingOrders(collect($this->items)->pluck('product_id'));
        $demand = [];
        foreach ($this->items as $item) {
            if ($item['product_id']) {
                $key = "{$item['product_id']}:{$item['variant_id']}";
                $demand[$key] = ($demand[$key] ?? 0) + (float) $item['quantity'];
            }
        }

        // Pre-flight every line before creating anything, so a failed sale
        // doesn't leave a half-built order behind.
        foreach ($this->items as $index => $item) {
            if (! $item['product_id']) {
                continue; // combo line — stock is derived from its components, not tracked directly
            }

            $product = Product::find($item['product_id']);
            $variant = $item['variant_id'] ? ProductVariant::find($item['variant_id']) : null;

            if (! $product) {
                continue;
            }

            $available = $stockService->available($product, $variant, $warehouse);

            $key = "{$item['product_id']}:{$item['variant_id']}";
            $needed = $demand[$key] ?? (float) $item['quantity'];

            if ($available < $needed) {
                $this->addError(
                    "items.{$index}.quantity",
                    sprintf('Only %s in stock for "%s".', self::qty($available), $item['label'])
                );
                return;
            }

            $heldQty = $held[$key]['quantity'] ?? 0.0;
            $free = max(0.0, $available - $heldQty);

            if ($needed > $free && ! $this->sellHeldStock) {
                $this->heldWarning = true;
                $this->addError(
                    "items.{$index}.quantity",
                    sprintf(
                        'Only %s free for "%s" — %s of the %s in stock are held by %d pending order(s). Tick "Sell anyway" to sell from held stock.',
                        self::qty($free), $item['label'], self::qty($heldQty), self::qty($available), $held[$key]['orders'],
                    )
                );
                return;
            }
        }

        try {
            $order = DB::transaction(function () use ($warehouse, $stockService) {
                $delivery = $this->isDelivery();
                $customerId = $this->customerId ?: ($this->accountsOn() ? $this->walkInCustomer()->id : null);

                $order = Order::create($delivery ? $this->deliveryOrderFields((int) $customerId) : [
                    'customer_id'     => $customerId,
                    'source'          => 'pos',
                    'status'          => OrderStatus::DELIVERED->value,
                    'payment_status'  => 'paid',
                    'fulfillment_status' => 'fulfilled',
                    'discount_amount' => $this->discountAmountClamped,
                    'placed_at'       => now(),
                    'confirmed_at'    => now(),
                    'completed_at'    => now(),
                ]);

                foreach ($this->items as $item) {
                    $product = Product::find($item['product_id']);
                    $variant = $item['variant_id'] ? ProductVariant::find($item['variant_id']) : null;

                    $quantity  = (float) $item['quantity'];
                    $unitPrice = (float) $item['unit_price'];

                    $orderItem = $order->items()->create([
                        'product_id'     => $item['product_id'],
                        'variant_id'     => $item['variant_id'] ?: null,
                        'product_name'   => $product?->name ?? 'Unknown product',
                        'variant_name'   => $variant?->sku,
                        'sku'            => $variant?->sku ?? $product?->code,
                        'quantity'       => $quantity,
                        'unit_price'     => $unitPrice,
                        'purchase_price' => $item['purchase_price'] !== '' ? $item['purchase_price'] : null,
                        'total_amount'   => $unitPrice * $quantity,
                    ]);

                    if ($delivery) {
                        continue; // packed from Orders before the courier picks it up
                    }

                    // Inventory off: taken below for the whole order, off stock_quantity (no ledger).
                    if ($product && ! $stockService->usesOwnStock()) {
                        $this->takeStock($orderItem, $product, $variant, $quantity, $warehouse, $stockService);
                    }

                    $orderItem->update(['delivered_quantity' => $quantity]);
                }

                // Pickup: Inventory off takes stock_quantity here. Delivery: a confirmed
                // order books its stock, the same as Sales › Create Order.
                if ($delivery ? $stockService->reservesOnConfirm() : $stockService->usesOwnStock()) {
                    $stockService->bookOrder($order->load('items'));
                }

                if ($delivery && $this->couponCode !== '' && $this->couponError === '') {
                    try {
                        app(CouponShippingService::class)->applyToOrder($order, $this->couponCode);
                    } catch (CouponNotApplicableException $e) {
                        $order->recalculateTotals();
                    }
                } else {
                    $order->recalculateTotals();
                }

                // Pickup pays in full; a delivery pays what was handed over now, the rest on delivery.
                $paid = $delivery ? round(min((float) $this->amountTendered, (float) $order->total_amount), 2) : (float) $order->total_amount;

                if ($paid > 0) {
                    $payment = $order->payments()->create([
                        'type'            => OrderPaymentType::PAYMENT,
                        'payment_method'  => $this->paymentMethod,
                        'cash_account_id' => $this->accountsOn() ? (int) $this->paymentAccountId : null,
                        'amount'          => $paid,
                        'status'          => 'paid',
                        'paid_at'         => now(),
                    ]);
                    $order->recalculateTotals();

                    // Held as the customer's advance until Accounts posts the sale and applies it.
                    app(PostOrderPayment::class)->handle($order->fresh('customer'), $payment->fresh('cashAccount'));
                }

                if ($delivery) {
                    $order->update(['payment_status' => $order->paid_amount <= 0 ? PaymentStatus::PENDING : ($order->due_amount > 0 ? PaymentStatus::PARTIAL : PaymentStatus::PAID)]);
                }

                PosSale::create([
                    'session_id'  => $this->session->id,
                    'order_id'    => $order->id,
                    'customer_id' => $customerId,
                    'created_by'  => auth()->id(),
                ]);

                if ($this->paymentMethod === PaymentMethod::CASH->value && $paid > 0) {
                    $this->session->cashMovements()->create([
                        'type'       => 'cash_in',
                        'amount'     => $paid,
                        'reason'     => ($delivery ? 'POS delivery order' : 'POS sale') . " — Order #{$order->id}",
                        'created_by' => auth()->id(),
                    ]);
                }

                return $order;
            });
        } catch (InsufficientStockException|\RuntimeException $e) {
            $this->dispatch('toast', ['type' => 'error', 'message' => $e->getMessage()]);
            return;
        }

        $delivery = $this->isDelivery();

        activity('sales')
            ->causedBy(auth()->user())
            ->performedOn($order)
            ->event('created')
            ->log(($delivery ? "POS delivery order — Order #{$order->id} placed" : "POS sale — Order #{$order->id} completed") . " on session #{$this->session->id}");

        $this->dispatch('toast', ['type' => 'success', 'message' => $delivery
            ? "Delivery order #{$order->id} placed — Orders থেকে pack করে courier book করুন।"
            : "Sale completed — Order #{$order->id}"]);

        $this->reset(['items', 'customerId', 'couponCode', 'shippingDiscount', 'couponError', 'discountAmount', 'amountTendered', 'productSearch', 'heldWarning', 'sellHeldStock',
            'fulfilment', 'shippingAddressId', 'newAddress', 'shippingMethodId', 'deliveryCharge']);
        $this->paymentMethod = PaymentMethod::CASH->value;
        $this->paymentAccountId = $this->defaultAccountFor(PaymentMethod::CASH->value);
    }

    /**
     * Home delivery: Confirmed (stock booked, packed + courier from Orders),
     * the customer's saved address or a new one, and the delivery charge with
     * the method's quote kept as meta when the cashier didn't change it.
     */
    protected function deliveryOrderFields(int $customerId): array
    {
        $customer = Customer::findOrFail($customerId);

        $address = $this->shippingAddressId !== ''
            ? DeliveryAddress::where('customer_id', $customer->id)->findOrFail($this->shippingAddressId)
            : DeliveryAddress::create([
                'customer_id'         => $customer->id,
                'address_type'        => 'Home',
                'name'                => $customer->full_name,
                'phone'               => $customer->phone,
                'full_address'        => trim($this->newAddress),
                // The first address a customer has becomes their default.
                'is_default_billing'  => ! DeliveryAddress::where('customer_id', $customer->id)->exists(),
                'is_default_shipping' => ! DeliveryAddress::where('customer_id', $customer->id)->exists(),
                'is_active'           => true,
            ]);

        $method = $this->shippingMethodId !== '' ? ShippingMethod::with('zone')->find($this->shippingMethodId) : null;
        $quote = $method ? $this->quote($method) : null;
        $charge = round($this->deliveryAmount, 2);

        return [
            'customer_id'         => $customer->id,
            'source'              => 'pos',
            'status'              => OrderStatus::CONFIRMED->value,
            'payment_status'      => PaymentStatus::PENDING,
            'fulfillment_status'  => 'unfulfilled',
            'discount_amount'     => $this->discountAmountClamped,
            'shipping_amount'     => $charge,
            'shipping_zone_id'    => $method?->shipping_zone_id,
            'shipping_method_id'  => $method?->id,
            'shipping_meta'       => $quote && abs($quote->amount - $charge) < 0.005 ? $quote->toMeta($method) : null,
            'billing_address_id'  => $address->id,
            'shipping_address_id' => $address->id,
            'placed_at'           => now(),
            'confirmed_at'        => now(),
        ];
    }

    /**
     * Inventory on: takes the line from its batches oldest-expiry first and
     * records them as packed (PackOrderItem), so Accounts can post the sale's
     * real cost. Whatever no batch covers comes off the plain balance — that
     * line then waits in Posting Pending as "Batch not picked", like any order.
     */
    protected function takeStock(OrderItem $item, Product $product, ?ProductVariant $variant, float $quantity, Warehouse $warehouse, StockService $stockService): void
    {
        $allocations = [];
        $left = $quantity;

        $batches = InventoryBatch::where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('variant_id', $variant?->id)
            ->active()
            ->fefoOrder()
            ->get();

        foreach ($batches as $batch) {
            if ($left <= 0.0005) {
                break;
            }
            $take = min($left, (float) $batch->quantity);
            if ($take > 0) {
                $allocations[$batch->id] = $take;
                $left -= $take;
            }
        }

        if ($allocations) {
            app(PackOrderItem::class)->handle($item, $allocations);
        }

        if ($left > 0.0005) {
            $stockService->decrease(
                $product, $variant, $left, 'sale',
                warehouse: $warehouse, reference: $item,
                note: "POS sale — Order #{$item->order_id}",
            );
        }
    }

    private static function qty(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * In stock / held by pending orders / free, per cart line — shown under
     * each line so the cashier sees what's already promised.
     *
     * @return array<int, array{stock: float, held: float, free: float, orders: int}>
     */
    protected function cartStock(): array
    {
        $stockService = app(StockService::class);
        $held = $stockService->heldByPendingOrders(collect($this->items)->pluck('product_id'));
        $out = [];

        foreach ($this->items as $i => $item) {
            $product = $item['product_id'] ? Product::find($item['product_id']) : null;
            if (! $product) {
                continue;
            }
            $variant = $item['variant_id'] ? ProductVariant::find($item['variant_id']) : null;
            if ($product->product_type->value === 'variable' && ! $variant) {
                continue; // stock is per variant
            }
            $stock = $stockService->available($product, $variant);
            $h = $held["{$item['product_id']}:{$item['variant_id']}"] ?? ['quantity' => 0.0, 'orders' => 0];
            $out[$i] = ['stock' => $stock, 'held' => $h['quantity'], 'free' => max(0.0, $stock - $h['quantity']), 'orders' => $h['orders']];
        }

        return $out;
    }

    public function render(): mixed
    {
        $productOptions = collect();
        if ($this->productSearch !== '') {
            $productOptions = Product::active()
                ->where('product_type', '!=', 'combo')
                ->where(fn ($q) => $q->where('name', 'like', "%{$this->productSearch}%")
                    ->orWhere('code', 'like', "%{$this->productSearch}%"))
                ->limit(20)
                ->get();
        } else {
            $productOptions = Product::active()->where('product_type', '!=', 'combo')->orderByDesc('id')->limit(20)->get();
        }

        $customers = Customer::orderBy('full_name')->get(['id', 'full_name', 'phone']);
        $customerOptions = $customers->mapWithKeys(fn ($c) => [$c->id => $c->full_name . ($c->phone ? " ({$c->phone})" : '')]);

        $coupons = Coupon::whereHas('promotion', fn ($q) => $q->where('status', 'active'))->orderBy('code')->get(['id', 'code']);
        $couponOptions = $coupons->mapWithKeys(fn ($c) => [$c->code => $c->code]);

        $selectedCustomer = $this->customerId ? Customer::find($this->customerId) : null;

        $productIds = collect($this->items)->pluck('product_id')->filter()->unique();
        $variantOptions = ProductVariant::active()
            ->whereIn('product_id', $productIds)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('product_id');

        return view('livewire.admin.sales.pos-screen', [
            'productOptions'   => $productOptions,
            'customerOptions'  => $customerOptions,
            'couponOptions'    => $couponOptions,
            'selectedCustomer' => $selectedCustomer,
            'variantOptions'   => $variantOptions,
            'cartStock'        => $this->cartStock(),
            'paymentAccounts'  => $this->cashAccounts(),
            'shippingMethods'  => $this->isDelivery() ? $this->shippingMethods() : [],
            'customerAddresses' => $this->isDelivery() ? $this->customerAddresses() : collect(),
        ])->layout('layouts.admin.admin');
    }
}
