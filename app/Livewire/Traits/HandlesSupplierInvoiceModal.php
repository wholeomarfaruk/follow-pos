<?php

namespace App\Livewire\Traits;

use App\Actions\Accounts\PostJournalEntry;
use App\Actions\Accounts\PostSupplierAdvance;
use App\Actions\Accounts\PostSupplierBill;
use App\Actions\Accounts\PostSupplierPayment;
use App\Actions\Accounts\PostSupplierReturn;
use App\Actions\Accounts\UnpostSupplierInvoice;
use App\Enums\Accounts\TransactionType;
use App\Enums\PaymentMethod;
use App\Enums\Purchase\SupplierInvoiceType;
use App\Exceptions\Accounts\DuplicateJournalEntryException;
use App\Exceptions\Purchase\SupplierInvoiceDeletionException;
use App\Exceptions\Purchase\SupplierInvoiceLockedException;
use App\Models\Account;
use App\Models\AccountsSupplierBill;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The full create/edit Supplier Invoice modal — type selector, item picker
 * (variant or simple product, with optional PO auto-fill), manual-amount
 * fallback for advance/payment, documents, and the Accounts postings each
 * type triggers. Shared by SupplierLedger (a fixed supplier from the route)
 * and SupplierInvoices (an admin-picked supplier in the modal) so the two
 * pages can never drift out of sync with each other again — implement
 * invoiceSupplierId() to say which supplier the modal is currently acting
 * on; everything else is identical between the two host components.
 */
trait HandlesSupplierInvoiceModal
{
    // create/edit invoice modal
    public bool    $invoiceModal      = false;
    public ?int    $editingInvoiceId  = null;
    public bool    $editingIsLocked   = false;
    public string  $invoiceType       = 'purchase';
    public string  $invoiceNumber     = '';
    public string  $invoiceDate       = '';
    public bool    $invoiceIsAdjusted = false;
    public string  $invoiceNotes      = '';

    // Which cash/bank account an "advance" or "payment" moved through.
    public string $advanceCashAccountId = '';
    public string $paymentCashAccountId = '';

    // How that money moved (App\Enums\PaymentMethod) and its reference —
    // shared by the advance, payment and "mark as paid now" sections, since
    // only one of them is on screen at a time.
    public string $paymentMethod    = 'cash';
    public string $paymentReference = '';

    // PO-based auto-fill (optional — a PO of the current supplier can be
    // selected to pull its line items in, fully editable afterwards)
    public string $purchaseOrderId = '';

    /**
     * "Payment" type only: which of this supplier's open AccountsSupplierBill
     * rows to apply the payment against, as billId => bool (checkbox state).
     * Left empty applies oldest-open-bill-first automatically.
     *
     * @var array<int, bool>
     */
    public array $paymentBills = [];

    // "Purchase" type only: optionally record a payment for this bill the
    // moment it's created, instead of a separate trip to Accounts > Payables.
    public bool   $markPaidNow          = false;
    public string $payNowAmount         = '';
    public string $payNowCashAccountId  = '';

    /** @var array<int, int> File IDs attached to the invoice being created/edited. */
    public array $documentIds = [];

    /**
     * Each item's `variant_id` is a "picker key": "v:{id}" for a specific
     * ProductVariant or "p:{id}" for a simple product (no variant row of its
     * own) — same convention as PurchaseOrderForm's line items, so one
     * searchable picker can offer both. `purchase_order_item_id` is set only
     * when this line came from (or is linked to) a PO line, so "invoiced so
     * far" can be tracked per PO item.
     *
     * @var array<int, array{variant_id: string, purchase_order_item_id: string, name: string, quantity: string, unit_price: string, amount: string}>
     */
    public array $items = [];

    public string $productSearch = '';
    public string $manualAmount  = '';

    /**
     * Which supplier the modal is currently creating/editing an invoice
     * for — a fixed value on a per-supplier ledger page, or the admin's
     * current pick on a cross-supplier invoice list.
     */
    abstract protected function invoiceSupplierId(): ?int;

    /**
     * Extra property names (beyond the trait's own) the host wants reset
     * when the modal opens/closes — e.g. a "modalSupplierId" picker on a
     * cross-supplier page. Override to add any; empty by default.
     */
    protected function extraInvoiceModalResetKeys(): array
    {
        return [];
    }

    public function openInvoiceModal(): void
    {
        $this->reset([
            'editingInvoiceId', 'editingIsLocked', 'invoiceType', 'invoiceNumber', 'invoiceIsAdjusted',
            'invoiceNotes', 'purchaseOrderId', 'items', 'productSearch', 'manualAmount', 'documentIds',
            'advanceCashAccountId', 'paymentCashAccountId', 'paymentBills', 'paymentMethod', 'paymentReference',
            'markPaidNow', 'payNowAmount', 'payNowCashAccountId', ...$this->extraInvoiceModalResetKeys(),
        ]);
        $this->invoiceType = 'purchase';
        $this->invoiceDate = now()->format('Y-m-d');
        $this->updatedPaymentMethod();
        $this->resetValidation();
        $this->invoiceModal = true;
    }

    /**
     * Picking a method pre-selects the matching "Paid from" account (bKash →
     * the bKash wallet, cheque → a bank account, …) in every money section —
     * still changeable by hand afterwards.
     */
    public function updatedPaymentMethod(): void
    {
        $accountId = PaymentMethod::tryFrom($this->paymentMethod)?->pickAccount($this->moneyAccounts());

        if (! $accountId) {
            return;
        }

        $this->paymentCashAccountId = (string) $accountId;
        $this->advanceCashAccountId = (string) $accountId;
        $this->payNowCashAccountId  = (string) $accountId;
    }

    /** Active cash / bank / MFS accounts money can be paid from. */
    protected function moneyAccounts()
    {
        return Account::active()->whereIn('subtype', ['cash', 'bank', 'mobile_banking'])->orderBy('code')->get();
    }

    protected function paymentMethodRules(): array
    {
        return [
            'paymentMethod'    => ['required', Rule::in(PaymentMethod::values(PaymentMethod::manual()))],
            'paymentReference' => 'nullable|string|max:100',
        ];
    }

    protected function chosenPaymentMethod(): PaymentMethod
    {
        return PaymentMethod::from($this->paymentMethod);
    }

    /** Advance and payment invoices are money moving; purchase / return aren't. */
    protected function movesMoney(SupplierInvoiceType $type): bool
    {
        return in_array($type, [SupplierInvoiceType::ADVANCE, SupplierInvoiceType::PAYMENT], true);
    }

    /**
     * Keeps the method on the invoice's own live (not reversed) journal
     * entries in step after an edit — metadata only, the lines are never
     * touched. A payment's entry isn't linked back to its invoice, so this
     * reaches advances (and anything else sourced from the invoice).
     */
    protected function syncEntryPaymentMethod(SupplierInvoice $invoice): void
    {
        if (! $this->movesMoney($invoice->type)) {
            return;
        }

        $invoice->journalEntries()
            ->where('transaction_type', '!=', TransactionType::REVERSAL->value)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('journal_entries as reversal')
                ->whereColumn('reversal.reversed_journal_entry_id', 'journal_entries.id'))
            ->update([
                'payment_method'    => $invoice->payment_method?->value,
                'payment_reference' => $invoice->payment_reference,
            ]);
    }

    /**
     * Opens a fresh Purchase invoice pre-filled with whatever is still
     * unbilled on the given PO — the PO page's "Create invoice" button lands
     * here through the supplier ledger's ?purchase_order= deep link.
     */
    public function openInvoiceModalForPurchaseOrder(int $purchaseOrderId): void
    {
        $order = PurchaseOrder::find($purchaseOrderId);

        if (! $order || $order->isCancelled()) {
            return;
        }

        $this->openInvoiceModal();
        $this->selectInvoiceSupplier($order->supplier_id);

        // A fixed-supplier host (the ledger) can't switch supplier, so a PO
        // of anyone else is ignored rather than billed to the wrong supplier.
        if ((int) $this->invoiceSupplierId() !== (int) $order->supplier_id) {
            return;
        }

        $this->purchaseOrderId = (string) $order->id;
        $this->updatedPurchaseOrderId();
    }

    /**
     * Hook for a host whose supplier is picked inside the modal (rather than
     * fixed by the route) to select it. No-op by default.
     */
    protected function selectInvoiceSupplier(int $supplierId): void
    {
        //
    }

    public function openEditInvoiceModal(int $id): void
    {
        $invoice = SupplierInvoice::with('items')->findOrFail($id);

        $this->reset(['items', 'productSearch', 'manualAmount', 'purchaseOrderId', 'advanceCashAccountId', 'paymentCashAccountId', 'paymentBills', 'paymentMethod', 'paymentReference']);

        $this->editingInvoiceId  = $invoice->id;
        // A "payment" invoice's amount is already applied against specific
        // bills via PostSupplierPayment — allocations can't be safely
        // re-run on edit, so it's always metadata-only here regardless of
        // serial position (see updateInvoice()).
        $this->editingIsLocked   = ! $invoice->isLatestSerial() || $invoice->type === SupplierInvoiceType::PAYMENT;
        $this->invoiceType       = $invoice->type->value;
        $this->invoiceNumber     = $invoice->invoice_number ?? '';
        $this->invoiceDate       = $invoice->invoice_date?->format('Y-m-d') ?? '';
        $this->invoiceIsAdjusted = $invoice->is_adjusted;
        $this->invoiceNotes      = $invoice->notes ?? '';
        $this->manualAmount      = (string) $invoice->amount;
        $this->documentIds       = $invoice->document_ids ?? [];
        $this->paymentMethod     = $invoice->payment_method?->value ?? PaymentMethod::CASH->value;
        $this->paymentReference  = $invoice->payment_reference ?? '';
        $this->items             = $invoice->items->map(fn ($item) => [
            'variant_id'             => $item->product_variant_id ? "v:{$item->product_variant_id}" : '',
            'purchase_order_item_id' => $item->purchase_order_item_id ? (string) $item->purchase_order_item_id : '',
            'name'                   => $item->name,
            'quantity'               => $item->quantity !== null ? (string) $item->quantity : '',
            'unit_price'             => $item->unit_price !== null ? (string) $item->unit_price : '',
            'amount'                 => (string) $item->amount,
        ])->all();

        // An advance re-posts through the same cash account if its amount is
        // edited, so start from the one it was originally paid from.
        if ($invoice->type === SupplierInvoiceType::ADVANCE) {
            $this->advanceCashAccountId = (string) ($invoice->journalEntries()
                ->where('purpose', 'supplier_advance')
                ->with('lines')
                ->first()
                ?->lines->firstWhere('credit', '>', 0)?->account_id ?? '');
        }

        $this->afterOpenEditInvoiceModal($invoice);

        $this->resetValidation();
        $this->invoiceModal = true;
    }

    /**
     * Hook for a host to pick up extra per-invoice state when opening the
     * edit modal (e.g. setting its own supplier picker to this invoice's
     * supplier_id). No-op by default.
     */
    protected function afterOpenEditInvoiceModal(SupplierInvoice $invoice): void
    {
        //
    }

    public function closeInvoiceModal(): void
    {
        $this->invoiceModal = false;
    }

    public function updatedInvoiceType(): void
    {
        if (! $this->invoiceTypeUsesItems()) {
            $this->items = [];
            $this->purchaseOrderId = '';
            $this->markPaidNow = false;
        }

        if ($this->invoiceType !== SupplierInvoiceType::PAYMENT->value) {
            $this->paymentBills = [];
        }
    }

    /**
     * Defaults the pay-now amount to the invoice's own total the moment the
     * checkbox is ticked, so the common case (paying the whole bill) needs
     * no typing — still fully editable for a partial payment.
     */
    public function updatedMarkPaidNow(): void
    {
        if ($this->markPaidNow && $this->payNowAmount === '') {
            $this->payNowAmount = (string) $this->itemsTotal;
        }
    }

    protected function invoiceTypeUsesItems(): bool
    {
        return SupplierInvoiceType::tryFrom($this->invoiceType)?->usesItems() ?? false;
    }

    /**
     * Pulls in the selected purchase order's still-unbilled lines as editable
     * invoice lines — quantity is what's left after earlier purchase invoices
     * against the same PO line (fully billed lines are skipped), unit price
     * comes from the PO; the admin can still add, remove, or change anything
     * afterwards. Re-selecting a PO (or clearing it) replaces any PO-sourced
     * lines already on the form without touching manually-added ones.
     */
    public function updatedPurchaseOrderId(): void
    {
        $this->items = collect($this->items)->filter(fn ($item) => $item['purchase_order_item_id'] === '')->values()->all();
        $this->resetValidation('purchaseOrderId');

        if ($this->purchaseOrderId === '') {
            return;
        }

        $order = PurchaseOrder::with([
            'items' => fn ($q) => $q->withSum('purchaseInvoiceItems as invoiced_quantity', 'quantity'),
            'items.product',
            'items.variant.values.productAttributeValue.attributeValue',
        ])->find($this->purchaseOrderId);

        // purchaseOrderId is client-settable: only a live PO of the supplier
        // this invoice is for may fill it.
        if (! $order || $order->isCancelled() || (int) $order->supplier_id !== (int) $this->invoiceSupplierId()) {
            $this->purchaseOrderId = '';
            return;
        }

        $this->invoiceType = SupplierInvoiceType::PURCHASE->value;

        foreach ($order->items as $poItem) {
            $quantity = round((float) $poItem->quantity - (float) $poItem->invoiced_quantity, 3);

            if ($quantity <= 0) {
                continue;
            }

            $labels = $poItem->variant
                ? $poItem->variant->values->map(fn ($v) => $v->productAttributeValue->attributeValue->value ?? '')->filter()->implode(' / ')
                : '';

            $name = trim(($poItem->product->name ?? 'Unknown product') . ($labels ? " ({$labels})" : '') . ($poItem->variant ? " [{$poItem->variant->sku}]" : ''));

            $unitPrice = $poItem->unit_price !== null ? (float) $poItem->unit_price : null;
            $amount    = $unitPrice !== null ? round($quantity * $unitPrice, 2) : (float) $poItem->total_amount;

            $this->items[] = [
                'variant_id'             => $poItem->product_variant_id ? "v:{$poItem->product_variant_id}" : "p:{$poItem->product_id}",
                'purchase_order_item_id' => (string) $poItem->id,
                'name'                   => $name,
                'quantity'               => (string) $quantity,
                'unit_price'             => $unitPrice !== null ? (string) $unitPrice : '',
                'amount'                 => $amount > 0 ? (string) $amount : '',
            ];
        }

        if (! collect($this->items)->contains(fn ($item) => $item['purchase_order_item_id'] !== '')) {
            $this->addError('purchaseOrderId', "Everything on {$order->order_number} is already billed.");
        }
    }

    public function addItem(?string $key = null): void
    {
        $name = '';
        $unitPrice = '';

        if ($key) {
            [$type, $id] = explode(':', $key, 2) + [null, null];

            if ($type === 'v') {
                $variant = ProductVariant::with('values.productAttributeValue.attributeValue', 'product')->find((int) $id);
                if ($variant) {
                    $labels = $variant->values->map(fn ($v) => $v->productAttributeValue->attributeValue->value)->implode(' / ');
                    $name = trim($variant->product->name . ($labels ? " ({$labels})" : '') . " [{$variant->sku}]");
                    $unitPrice = $variant->purchase_price !== null ? (string) $variant->purchase_price : '';
                }
            } elseif ($type === 'p') {
                $product = Product::find((int) $id);
                if ($product) {
                    $name = trim("{$product->name} [{$product->code}]");
                    $unitPrice = $product->purchase_price !== null ? (string) $product->purchase_price : '';
                }
            }
        }

        $this->items[] = [
            'variant_id'             => $key ?? '',
            'purchase_order_item_id' => '',
            'name'                   => $name,
            'quantity'               => '1',
            'unit_price'             => $unitPrice,
            'amount'                 => '',
        ];

        $this->productSearch = '';
        $this->recalculateItemAmount(count($this->items) - 1);
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function updatedItems($value, $key): void
    {
        [$index, $field] = explode('.', $key) + [null, null];

        if (in_array($field, ['quantity', 'unit_price']) && $index !== null) {
            $this->recalculateItemAmount((int) $index);
        }
    }

    protected function recalculateItemAmount(int $index): void
    {
        $qty   = (float) ($this->items[$index]['quantity'] ?? 0);
        $price = (float) ($this->items[$index]['unit_price'] ?? 0);

        if ($qty > 0 && $price > 0) {
            $this->items[$index]['amount'] = (string) round($qty * $price, 2);
        }
    }

    public function getItemsTotalProperty(): float
    {
        return collect($this->items)->sum(fn ($item) => (float) ($item['amount'] ?? 0));
    }

    public function saveInvoice(): void
    {
        if ($this->editingInvoiceId) {
            $this->updateInvoice();
            return;
        }

        $this->createInvoice();
    }

    /**
     * Resolves one item row's "v:{id}"/"p:{id}" picker key into a
     * product_variant_id for saving (null for a simple product — there is no
     * product_id column on supplier_invoice_items yet, so a simple-product
     * line is identified only by its free-text `name`).
     */
    protected function resolveVariantId(string $key): ?int
    {
        [$type, $id] = explode(':', $key, 2) + [null, null];

        return $type === 'v' && $id ? (int) $id : null;
    }

    protected function itemRules(): array
    {
        return [
            'items'               => 'required|array|min:1',
            'items.*.name'        => 'required|string|max:255',
            'items.*.quantity'    => 'nullable|numeric|min:0',
            'items.*.unit_price'  => 'nullable|numeric|min:0',
            'items.*.amount'      => 'required|numeric|min:0.01',
        ];
    }

    /**
     * Extra validation rules the host wants applied before create/update
     * (e.g. requiring its own supplier picker). Empty by default.
     */
    protected function extraInvoiceValidationRules(): array
    {
        return [];
    }

    protected function createInvoice(): void
    {
        if ($this->invoiceType === SupplierInvoiceType::PAYMENT->value) {
            $this->createPaymentInvoice();
            return;
        }

        $supplier = Supplier::findOrFail($this->invoiceSupplierId() ?: 0);

        $usesItems = $this->invoiceTypeUsesItems();

        $rules = [
            ...$this->extraInvoiceValidationRules(),
            'invoiceType'   => ['required', Rule::enum(SupplierInvoiceType::class)],
            'invoiceNumber' => 'nullable|string|max:100',
            'invoiceDate'   => 'nullable|date',
            'invoiceNotes'  => 'nullable|string',
        ];

        $rules = $usesItems ? [...$rules, ...$this->itemRules()] : [...$rules, 'manualAmount' => 'required|numeric|min:0.01'];

        if ($this->invoiceType === SupplierInvoiceType::ADVANCE->value) {
            $rules['advanceCashAccountId'] = 'required|exists:accounts,id';
            $rules += $this->paymentMethodRules();
        }

        if ($this->invoiceType === SupplierInvoiceType::PURCHASE->value && $this->markPaidNow) {
            $rules['payNowAmount']        = 'required|numeric|min:0.01';
            $rules['payNowCashAccountId'] = 'required|exists:accounts,id';
            $rules += $this->paymentMethodRules();
        }

        $this->validate($rules);

        $amount = $usesItems ? $this->itemsTotal : (float) $this->manualAmount;

        if ($amount <= 0) {
            $this->addError('items', 'Total amount must be greater than zero.');
            return;
        }

        if ($usesItems && ! $this->purchaseOrderLinesBelongTo($supplier)) {
            return;
        }

        $purchaseOrderId = $usesItems ? $this->linkedPurchaseOrderId() : null;

        // One transaction for the invoice, its lines and its Accounts
        // postings, so a posting failure can't leave a half-recorded invoice
        // that has already moved Supplier.balance.
        $invoice = DB::transaction(function () use ($supplier, $amount, $usesItems, $purchaseOrderId) {
            $serial = ($supplier->invoices()->max('serial_number') ?? 0) + 1;

            $invoice = SupplierInvoice::create([
                'supplier_id'       => $supplier->id,
                'purchase_order_id' => $purchaseOrderId,
                'serial_number'     => $serial,
                'invoice_number'    => $this->invoiceNumber ?: null,
                'type'              => $this->invoiceType,
                'amount'            => $amount,
                'payment_method'    => $this->invoiceType === SupplierInvoiceType::ADVANCE->value ? $this->paymentMethod : null,
                'payment_reference' => $this->invoiceType === SupplierInvoiceType::ADVANCE->value ? ($this->paymentReference ?: null) : null,
                'is_adjusted'       => $this->invoiceIsAdjusted,
                'invoice_date'      => $this->invoiceDate ?: null,
                'notes'             => $this->invoiceNotes ?: null,
                'document_ids'      => $this->documentIds ?: null,
            ]);

            if ($usesItems) {
                $this->createInvoiceItems($invoice);
            }

            $bill = $this->postInvoiceToAccounts($invoice);

            if ($invoice->type === SupplierInvoiceType::PURCHASE && $this->markPaidNow && $bill) {
                $this->payBillNow($supplier, $bill);
            }

            return $invoice;
        });

        activity('purchase')
            ->causedBy(auth()->user())
            ->performedOn($invoice)
            ->withProperties(['type' => $invoice->type->value, 'amount' => $invoice->amount])
            ->event('created')
            ->log("Supplier invoice #{$invoice->serial_number} ({$invoice->type->label()}) recorded for \"{$supplier->name}\"");

        $this->invoiceModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Invoice recorded successfully']);
    }

    /** @return \Illuminate\Support\Collection<int, int> PO line ids the form's lines are linked to */
    protected function linkedPurchaseOrderItemIds()
    {
        return collect($this->items)->pluck('purchase_order_item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * Line links are client-settable too — refuse (with an error) any line
     * pointing at a PO line that isn't on one of this supplier's POs.
     */
    protected function purchaseOrderLinesBelongTo(Supplier $supplier): bool
    {
        $ids = $this->linkedPurchaseOrderItemIds();

        if ($ids->isEmpty()) {
            return true;
        }

        $owned = PurchaseOrderItem::whereIn('id', $ids)
            ->whereHas('purchaseOrder', fn ($q) => $q->where('supplier_id', $supplier->id))
            ->count();

        if ($owned !== $ids->count()) {
            $this->addError('items', 'Some lines are linked to a purchase order of another supplier — remove them and add them again.');
            return false;
        }

        return true;
    }

    /** Header-level PO link, taken from the lines so it can't drift from them. */
    protected function linkedPurchaseOrderId(): ?int
    {
        $ids = $this->linkedPurchaseOrderItemIds();

        $purchaseOrderId = $ids->isEmpty() ? null : PurchaseOrderItem::whereIn('id', $ids)->min('purchase_order_id');

        return $purchaseOrderId ? (int) $purchaseOrderId : null;
    }

    protected function createInvoiceItems(SupplierInvoice $invoice): void
    {
        foreach ($this->items as $item) {
            $invoice->items()->create([
                'product_variant_id'     => $this->resolveVariantId($item['variant_id']),
                'purchase_order_item_id' => $item['purchase_order_item_id'] ?: null,
                'name'                   => $item['name'],
                'quantity'               => $item['quantity'] !== '' ? $item['quantity'] : null,
                'unit_price'             => $item['unit_price'] !== '' ? $item['unit_price'] : null,
                'amount'                 => $item['amount'],
            ]);
        }
    }

    /**
     * Posts whatever this invoice's type books in Accounts: a bill for a
     * purchase, an advance, or a purchase return. Payments post through
     * PostSupplierPayment instead (createPaymentInvoice()). Returns the bill
     * for a purchase so "mark as paid now" can settle it.
     */
    protected function postInvoiceToAccounts(SupplierInvoice $invoice): ?AccountsSupplierBill
    {
        return match ($invoice->type) {
            SupplierInvoiceType::PURCHASE => $this->postBillToAccounts($invoice),
            SupplierInvoiceType::ADVANCE  => $this->postAdvanceToAccounts($invoice),
            SupplierInvoiceType::RETURN   => $this->postReturnToAccounts($invoice),
            SupplierInvoiceType::PAYMENT  => null,
        };
    }

    /**
     * "Payment" type never creates a plain SupplierInvoice directly — it
     * goes through PostSupplierPayment so the same call both allocates
     * against the chosen (or auto-picked) open bills AND records the
     * SupplierInvoice that keeps Supplier.balance in sync. This is the fix
     * for the two ledgers (Purchase module's Supplier.balance vs Accounts'
     * AccountsSupplierBill) silently drifting apart — previously a
     * "payment" here only touched Supplier.balance with no bill selection
     * and no journal entry at all.
     */
    protected function createPaymentInvoice(): void
    {
        $supplier = Supplier::findOrFail($this->invoiceSupplierId() ?: 0);

        $this->validate([
            ...$this->extraInvoiceValidationRules(),
            'invoiceNumber'        => 'nullable|string|max:100',
            'invoiceDate'          => 'nullable|date',
            'invoiceNotes'         => 'nullable|string',
            'manualAmount'         => 'required|numeric|min:0.01',
            'paymentCashAccountId' => 'required|exists:accounts,id',
            ...$this->paymentMethodRules(),
        ]);

        $allocations = collect($this->paymentBills)
            ->filter()
            ->keys()
            ->mapWithKeys(function ($billId) use ($supplier) {
                $bill = AccountsSupplierBill::where('supplier_id', $supplier->id)->find($billId);
                return $bill ? [$billId => $bill->amountDue()] : [];
            })
            ->all();

        app(PostSupplierPayment::class)->handle(
            supplier: $supplier,
            payableAccountId: $this->accountId('2100'),
            cashAccountId: (int) $this->paymentCashAccountId,
            amount: (float) $this->manualAmount,
            entryDate: $this->invoiceDate ?: now()->toDateString(),
            allocations: $allocations,
            description: $this->invoiceNotes ?: null,
            invoiceNumber: $this->invoiceNumber ?: null,
            paymentMethod: $this->chosenPaymentMethod(),
            paymentReference: $this->paymentReference ?: null,
        );

        activity('purchase')
            ->causedBy(auth()->user())
            ->withProperties(['type' => 'payment', 'amount' => $this->manualAmount])
            ->event('created')
            ->log("Payment of {$this->manualAmount} recorded for \"{$supplier->name}\"");

        $this->invoiceModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Payment recorded']);
    }

    /**
     * "Mark as paid now" on a fresh Purchase invoice — applies a payment to
     * the bill that was just posted, right after creating it, instead of a
     * separate trip to Accounts > Payables.
     */
    protected function payBillNow(Supplier $supplier, AccountsSupplierBill $bill): void
    {
        app(PostSupplierPayment::class)->handle(
            supplier: $supplier,
            payableAccountId: $this->accountId('2100'),
            cashAccountId: (int) $this->payNowCashAccountId,
            amount: (float) $this->payNowAmount,
            entryDate: $this->invoiceDate ?: now()->toDateString(),
            allocations: [$bill->id => min((float) $this->payNowAmount, $bill->amountDue())],
            paymentMethod: $this->chosenPaymentMethod(),
            paymentReference: $this->paymentReference ?: null,
        );
    }

    /**
     * Only the latest invoice may have its type/amount/items changed —
     * every older invoice is locked to metadata-only edits (invoice
     * number, date, adjusted flag, notes, documents), since changing an
     * older invoice's amount would silently corrupt every running balance
     * and delta computed for invoices recorded after it.
     */
    protected function updateInvoice(): void
    {
        $invoice = SupplierInvoice::findOrFail($this->editingInvoiceId);

        // A "payment" invoice's amount is already applied to specific bills
        // via PostSupplierPayment — those allocations can't be safely
        // re-run on edit, so it never gets the full amount/items form
        // regardless of serial position.
        if ($invoice->isLatestSerial() && $invoice->type !== SupplierInvoiceType::PAYMENT) {
            $this->updateLatestInvoice($invoice);
            return;
        }

        $this->updateInvoiceMetadata($invoice);
    }

    protected function updateInvoiceMetadata(SupplierInvoice $invoice): void
    {
        $movesMoney = $this->movesMoney($invoice->type);

        $this->validate([
            'invoiceNumber' => 'nullable|string|max:100',
            'invoiceDate'   => 'nullable|date',
            'invoiceNotes'  => 'nullable|string',
            ...($movesMoney ? $this->paymentMethodRules() : []),
        ]);

        $invoice->update([
            'invoice_number' => $this->invoiceNumber ?: null,
            'is_adjusted'    => $this->invoiceIsAdjusted,
            'invoice_date'   => $this->invoiceDate ?: null,
            'notes'          => $this->invoiceNotes ?: null,
            'document_ids'   => $this->documentIds ?: null,
            ...($movesMoney ? ['payment_method' => $this->paymentMethod, 'payment_reference' => $this->paymentReference ?: null] : []),
        ]);

        $this->syncEntryPaymentMethod($invoice);

        activity('purchase')
            ->causedBy(auth()->user())
            ->performedOn($invoice)
            ->event('updated')
            ->log("Supplier invoice #{$invoice->serial_number} ({$invoice->type->label()}) updated for \"{$invoice->supplier->name}\"");

        $this->invoiceModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Invoice updated successfully']);
    }

    protected function updateLatestInvoice(SupplierInvoice $invoice): void
    {
        $usesItems = $this->invoiceTypeUsesItems();
        $amount = $usesItems ? $this->itemsTotal : (float) $this->manualAmount;

        $originalType   = $invoice->type;
        $originalAmount = (float) $invoice->amount;

        $rules = [
            'invoiceType'   => ['required', Rule::enum(SupplierInvoiceType::class)],
            'invoiceNumber' => 'nullable|string|max:100',
            'invoiceDate'   => 'nullable|date',
            'invoiceNotes'  => 'nullable|string',
        ];

        $rules = $usesItems ? [...$rules, ...$this->itemRules()] : [...$rules, 'manualAmount' => 'required|numeric|min:0.01'];

        // An advance whose type or amount changes is re-posted, which needs
        // the cash/bank account it moved through.
        $reposts = $originalType->value !== $this->invoiceType || abs($amount - $originalAmount) >= 0.01;
        if ($this->invoiceType === SupplierInvoiceType::ADVANCE->value && $reposts) {
            $rules['advanceCashAccountId'] = 'required|exists:accounts,id';
        }

        $isAdvance = $this->invoiceType === SupplierInvoiceType::ADVANCE->value;
        if ($isAdvance) {
            $rules += $this->paymentMethodRules();
        }

        $this->validate($rules);

        // A payment is applied to specific bills when it's created — turning
        // another invoice into one here would skip that, so it must be
        // recorded as a new transaction instead.
        if ($this->invoiceType === SupplierInvoiceType::PAYMENT->value) {
            $this->addError('invoiceType', 'Record a payment as a new transaction instead of changing this one.');
            return;
        }

        if ($amount <= 0) {
            $this->addError('items', 'Total amount must be greater than zero.');
            return;
        }

        if ($usesItems && ! $this->purchaseOrderLinesBelongTo($invoice->supplier)) {
            return;
        }

        try {
            DB::transaction(function () use ($invoice, $amount, $usesItems, $originalType, $originalAmount, $isAdvance) {
                $invoice->update([
                    'invoice_number' => $this->invoiceNumber ?: null,
                    'type'           => $this->invoiceType,
                    'amount'         => $amount,
                    'payment_method' => $isAdvance ? $this->paymentMethod : null,
                    'payment_reference' => $isAdvance ? ($this->paymentReference ?: null) : null,
                    'is_adjusted'    => $this->invoiceIsAdjusted,
                    'invoice_date'   => $this->invoiceDate ?: null,
                    'notes'          => $this->invoiceNotes ?: null,
                    'document_ids'   => $this->documentIds ?: null,
                ]);

                $this->reconcileAccountsOnEdit($invoice, $originalType, $originalAmount);
                $this->syncEntryPaymentMethod($invoice);

                $invoice->items()->delete();

                if ($usesItems) {
                    $this->createInvoiceItems($invoice);
                }

                $invoice->update(['purchase_order_id' => $usesItems ? $this->linkedPurchaseOrderId() : null]);
            });
        } catch (SupplierInvoiceLockedException $e) {
            $this->addError('invoiceType', $e->getMessage());
            $this->dispatch('toast', ['type' => 'error', 'message' => $e->getMessage()]);
            return;
        }

        activity('purchase')
            ->causedBy(auth()->user())
            ->performedOn($invoice)
            ->withProperties(['type' => $invoice->type->value, 'amount' => $invoice->amount])
            ->event('updated')
            ->log("Supplier invoice #{$invoice->serial_number} ({$invoice->type->label()}) updated for \"{$invoice->supplier->name}\"");

        $this->invoiceModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Invoice updated successfully']);
    }

    /**
     * Books the purchase (Dr Inventory / Cr Accounts Payable) and opens an
     * AccountsSupplierBill for payment allocation, linked to this real
     * SupplierInvoice — so bills seen under Accounts always trace back to
     * an actual purchase recorded here. Safely ignored if this invoice was
     * already posted (shouldn't happen on create, but keeps this idempotent
     * like the sales-side equivalent).
     */
    protected function postBillToAccounts(SupplierInvoice $invoice): ?AccountsSupplierBill
    {
        try {
            return app(PostSupplierBill::class)->handle(
                supplier: $invoice->supplier,
                inventoryAccountId: $this->accountId('1200'),
                payableAccountId: $this->accountId('2100'),
                amount: (float) $invoice->amount,
                entryDate: $invoice->invoice_date?->toDateString() ?? now()->toDateString(),
                supplierInvoiceId: $invoice->id,
                description: "Purchase invoice #{$invoice->serial_number} — {$invoice->supplier->name}",
            );
        } catch (DuplicateJournalEntryException) {
            // Already posted for this invoice — nothing to do.
            return AccountsSupplierBill::where('supplier_invoice_id', $invoice->id)->first();
        }
    }

    /**
     * Books an advance payment to the supplier (Dr Supplier Advance / Cr the
     * chosen cash/bank account) and opens an AccountsSupplierAdvance so it
     * can later be applied against a bill from Accounts > Payables. Safely
     * ignored if this invoice was already posted.
     */
    protected function postAdvanceToAccounts(SupplierInvoice $invoice): void
    {
        try {
            app(PostSupplierAdvance::class)->handle(
                supplier: $invoice->supplier,
                supplierAdvanceAccountId: $this->accountId('1150'),
                cashAccountId: (int) $this->advanceCashAccountId,
                amount: (float) $invoice->amount,
                entryDate: $invoice->invoice_date?->toDateString() ?? now()->toDateString(),
                supplierInvoiceId: $invoice->id,
                description: "Advance invoice #{$invoice->serial_number} — {$invoice->supplier->name}",
                paymentMethod: $invoice->payment_method,
                paymentReference: $invoice->payment_reference,
            );
        } catch (DuplicateJournalEntryException) {
            // Already posted for this invoice — nothing to do.
        }
    }

    /**
     * Books goods sent back to the supplier (Cr Inventory; Dr Payable against
     * their open bills, any excess Dr Supplier Advance) — see
     * PostSupplierReturn. Safely ignored if this invoice was already posted.
     */
    protected function postReturnToAccounts(SupplierInvoice $invoice): void
    {
        try {
            app(PostSupplierReturn::class)->handle(
                supplier: $invoice->supplier,
                payableAccountId: $this->accountId('2100'),
                supplierAdvanceAccountId: $this->accountId('1150'),
                inventoryAccountId: $this->accountId('1200'),
                amount: (float) $invoice->amount,
                entryDate: $invoice->invoice_date?->toDateString() ?? now()->toDateString(),
                supplierInvoiceId: $invoice->id,
                description: "Purchase return #{$invoice->serial_number} — {$invoice->supplier->name}",
            );
        } catch (DuplicateJournalEntryException) {
            // Already posted for this invoice — nothing to do.
        }
    }

    protected function accountId(string $code): int
    {
        return Account::where('code', $code)->value('id')
            ?? throw new \RuntimeException("Chart of accounts is missing account code {$code}.");
    }

    /**
     * Keeps the Accounts side in step when the latest invoice's type or
     * amount is edited. A posted journal entry is never mutated (Golden
     * Rule #2): a purchase that stays a purchase gets a delta adjustment
     * (reconcileBillOnEdit(), so a part-paid bill keeps its payments);
     * any other change reverses what the invoice posted and posts it again
     * as its new type. Throws SupplierInvoiceLockedException (rolling the
     * edit back) when Accounts has already settled the original posting.
     */
    protected function reconcileAccountsOnEdit(SupplierInvoice $invoice, SupplierInvoiceType $originalType, float $originalAmount): void
    {
        $typeChanged   = $originalType !== $invoice->type;
        $amountChanged = abs((float) $invoice->amount - $originalAmount) >= 0.01;

        if (! $typeChanged && ! $amountChanged) {
            return;
        }

        if (! $typeChanged && $invoice->type === SupplierInvoiceType::PURCHASE) {
            $this->reconcileBillOnEdit($invoice, $originalAmount);
            return;
        }

        app(UnpostSupplierInvoice::class)->handle($invoice, "Supplier invoice #{$invoice->serial_number} edited");
        $this->postInvoiceToAccounts($invoice);
    }

    /**
     * Purchase stays purchase, amount changed: post a small adjustment entry
     * for the delta and correct the AccountsSupplierBill total instead of
     * re-posting the full amount.
     */
    protected function reconcileBillOnEdit(SupplierInvoice $invoice, float $originalAmount): void
    {
        $bill = AccountsSupplierBill::where('supplier_invoice_id', $invoice->id)->first();

        if (! $bill) {
            $this->postBillToAccounts($invoice);
            return;
        }

        $delta = round((float) $invoice->amount - $originalAmount, 2);

        if (abs($delta) < 0.01) {
            return;
        }

        // The bill can't end up owing less than has already been paid on it.
        if ((float) $invoice->amount + 0.009 < (float) $bill->amount_allocated) {
            throw SupplierInvoiceLockedException::belowPaid((float) $bill->amount_allocated);
        }

        $this->postBillAdjustment($invoice, $bill, $delta);
    }

    protected function postBillAdjustment(SupplierInvoice $invoice, AccountsSupplierBill $bill, float $delta): void
    {
        DB::transaction(function () use ($invoice, $bill, $delta) {
            $inventoryAccountId = $this->accountId('1200');
            $payableAccountId   = $this->accountId('2100');
            $amount             = abs($delta);

            $lines = $delta > 0
                ? [
                    ['account_id' => $inventoryAccountId, 'debit' => $amount],
                    ['account_id' => $payableAccountId, 'credit' => $amount],
                ]
                : [
                    ['account_id' => $payableAccountId, 'debit' => $amount],
                    ['account_id' => $inventoryAccountId, 'credit' => $amount],
                ];

            app(PostJournalEntry::class)->handle([
                'entry_date'       => $invoice->invoice_date?->toDateString() ?? now()->toDateString(),
                'description'      => "Purchase invoice #{$invoice->serial_number} adjustment — {$invoice->supplier->name}",
                'transaction_type' => TransactionType::SUPPLIER_BILL->value,
                // Sourced (without a purpose, so repeat adjustments are fine)
                // so UnpostSupplierInvoice reverses it along with the bill.
                'source_type'      => SupplierInvoice::class,
                'source_id'        => $invoice->id,
            ], $lines);

            $bill->amount = round((float) $bill->amount + $delta, 2);
            $bill->status = $bill->amountDue() <= 0.01
                ? 'paid'
                : ((float) $bill->amount_allocated > 0 ? 'partial' : 'open');
            $bill->save();
        });
    }

    public function deleteInvoice(int $id): void
    {
        $invoice = SupplierInvoice::with('supplier')->findOrFail($id);

        // Undo the Accounts side first, in the same transaction, so a delete
        // the model then refuses (not the latest, a payment) changes nothing.
        try {
            DB::transaction(function () use ($invoice) {
                app(UnpostSupplierInvoice::class)->handle($invoice, "Supplier invoice #{$invoice->serial_number} deleted");
                $invoice->delete();
            });
        } catch (SupplierInvoiceDeletionException|SupplierInvoiceLockedException $e) {
            $this->dispatch('toast', ['type' => 'error', 'message' => $e->getMessage()]);
            return;
        }

        activity('purchase')
            ->causedBy(auth()->user())
            ->withProperties(['type' => $invoice->type->value, 'amount' => $invoice->amount])
            ->event('deleted')
            ->log("Supplier invoice #{$invoice->serial_number} ({$invoice->type->label()}) deleted for \"{$invoice->supplier->name}\"");

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Invoice deleted']);
    }

    /**
     * View data the invoice modal's blade needs — purchase orders for the
     * given supplier (for PO auto-fill) and the product/variant picker
     * results for the current search, plus the cash/bank account list for
     * the advance field. Pass $supplierId explicitly (SupplierLedger has a
     * fixed one; SupplierInvoices passes its current modalSupplierId pick,
     * which can be empty before anything is chosen).
     */
    protected function invoiceModalViewData(?int $supplierId): array
    {
        $purchaseOrders = collect();
        if ($this->invoiceModal && $supplierId) {
            $purchaseOrders = PurchaseOrder::with(['items' => fn ($q) => $q->withSum('purchaseInvoiceItems as invoiced_amount', 'amount')])
                ->where('supplier_id', $supplierId)
                ->where('status', '!=', 'cancelled')
                ->orderByDesc('id')
                ->get()
                ->map(function (PurchaseOrder $order) {
                    $order->invoiced_total = (float) $order->items->sum('invoiced_amount');
                    return $order;
                });
        }

        $productOptions = collect();
        if ($this->invoiceModal && $this->productSearch !== '') {
            $variants = ProductVariant::with('product', 'values.productAttributeValue.attributeValue')
                ->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$this->productSearch}%"))
                ->orWhere('sku', 'like', "%{$this->productSearch}%")
                ->limit(10)
                ->get();

            $productOptions = $variants->mapWithKeys(function (ProductVariant $variant) {
                $labels = $variant->values->map(fn ($v) => $v->productAttributeValue->attributeValue->value)->implode(' / ');
                $label = trim(($variant->product->name ?? 'Unknown product') . ($labels ? " ({$labels})" : '') . " [{$variant->sku}]");
                return ["v:{$variant->id}" => $label];
            });

            $simpleProducts = Product::where('product_type', 'simple')
                ->whereDoesntHave('variants')
                ->where(fn ($q) => $q->where('name', 'like', "%{$this->productSearch}%")->orWhere('code', 'like', "%{$this->productSearch}%"))
                ->limit(10)
                ->get(['id', 'name', 'code']);

            $productOptions = $productOptions->union(
                $simpleProducts->mapWithKeys(fn (Product $product) => ["p:{$product->id}" => trim("{$product->name} [{$product->code}]")])
            );
        }

        $openBills = collect();
        if ($this->invoiceModal && $supplierId && $this->invoiceType === SupplierInvoiceType::PAYMENT->value) {
            $openBills = AccountsSupplierBill::where('supplier_id', $supplierId)
                ->whereIn('status', ['open', 'partial'])
                ->oldest()
                ->get();
        }

        return [
            'purchaseOrders' => $purchaseOrders,
            'productOptions' => $productOptions,
            'cashAccounts'   => Account::active()->whereIn('subtype', ['cash', 'bank', 'mobile_banking'])->orderBy('code')->get(),
            'openBills'      => $openBills,
        ];
    }
}
