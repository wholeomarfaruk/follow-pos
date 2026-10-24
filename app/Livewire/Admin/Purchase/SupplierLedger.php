<?php

namespace App\Livewire\Admin\Purchase;

use App\Enums\Purchase\SupplierInvoiceType;
use App\Livewire\Traits\HandlesSupplierInvoiceModal;
use App\Livewire\Traits\WithMediaPicker;
use App\Models\File;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\Purchase\PurchaseOrderProgress;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SupplierLedger extends Component
{
    use WithPagination;
    use WithMediaPicker;
    use HandlesSupplierInvoiceModal;

    public int $supplierId;

    /** "transactions" (the invoice ledger) or "orders" (this supplier's POs). */
    #[Url(except: 'transactions')]
    public string $tab = 'transactions';

    protected string $paginationTheme = 'tailwind';

    public function mount(int $supplierId): void
    {
        $this->supplierId  = $supplierId;
        $this->invoiceDate = now()->format('Y-m-d');

        // Deep link from a PO page's "Create invoice": ?purchase_order=X
        // opens the modal pre-filled with that PO's unbilled lines.
        $purchaseOrderId = (int) request()->query('purchase_order');
        if ($purchaseOrderId && PurchaseOrder::whereKey($purchaseOrderId)->where('supplier_id', $supplierId)->exists()) {
            $this->openInvoiceModalForPurchaseOrder($purchaseOrderId);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['transactions', 'orders'], true) ? $tab : 'transactions';
    }

    protected function invoiceSupplierId(): ?int
    {
        return $this->supplierId;
    }

    /**
     * Set a transient `balance_after` on each invoice: the supplier's due (+)
     * or advance (-) balance immediately after that invoice was recorded.
     *
     * Invoices are walked in the newest-first order they're displayed in.
     * The walk starts at the current Supplier balance, offset by the signed
     * amount of every invoice newer than the first one on this page (so the
     * figures are correct on page 2+, not just page 1).
     */
    protected function attachRunningBalances(Supplier $supplier, $invoices): void
    {
        if ($invoices->isEmpty()) {
            return;
        }

        $firstSerialOnPage = $invoices->first()->serial_number;

        $newerInvoices = $supplier->invoices()
            ->where('serial_number', '>', $firstSerialOnPage)
            ->get(['type', 'amount']);

        $newerDelta = $newerInvoices->sum(fn (SupplierInvoice $invoice) => $invoice->type->signedAmount((float) $invoice->amount));

        $runningBalance = (float) $supplier->balance - $newerDelta;
        $isFirstRow     = $newerInvoices->isEmpty();

        foreach ($invoices as $invoice) {
            $invoice->balance_after   = $runningBalance;
            $invoice->is_latest_serial = $isFirstRow;

            $runningBalance -= $invoice->type->signedAmount((float) $invoice->amount);
            $isFirstRow = false;
        }
    }

    /**
     * Batch-load every document referenced across the page's invoices in one
     * query and attach the resolved File models to each invoice, avoiding a
     * File::find() per document per row in the view.
     */
    protected function attachDocuments($invoices): void
    {
        $documentIds = $invoices->flatMap(fn (SupplierInvoice $invoice) => $invoice->document_ids ?? [])->unique();

        if ($documentIds->isEmpty()) {
            return;
        }

        $files = File::whereIn('id', $documentIds)->get()->keyBy('id');

        foreach ($invoices as $invoice) {
            $invoice->documents = collect($invoice->document_ids ?? [])
                ->map(fn ($id) => $files->get($id))
                ->filter()
                ->values();
        }
    }

    public function render(): mixed
    {
        $supplier = Supplier::findOrFail($this->supplierId);

        $invoices = $supplier->invoices()
            ->with('purchaseOrder:id,order_number')
            ->withCount('items')
            ->orderByDesc('serial_number')
            ->paginate(15);

        $this->attachRunningBalances($supplier, $invoices->getCollection());
        $this->attachDocuments($invoices->getCollection());

        // Named apart from the modal's own `purchaseOrders` (the PO picker).
        $supplierOrders = null;
        if ($this->tab === 'orders') {
            $supplierOrders = $supplier->purchaseOrders()->with('items')->orderByDesc('id')->paginate(15, ['*'], 'poPage');
            app(PurchaseOrderProgress::class)->attach($supplierOrders->getCollection());
        }

        return view('livewire.admin.purchase.supplier-ledger', [
            'supplier'      => $supplier,
            'invoices'      => $invoices,
            'supplierOrders' => $supplierOrders,
            'openOrderCount' => $supplier->openPurchaseOrders()->count(),
            'purchaseTotal' => $supplier->invoices()->where('type', SupplierInvoiceType::PURCHASE)->sum('amount'),
            'paidTotal'     => $supplier->invoices()->whereIn('type', [SupplierInvoiceType::ADVANCE, SupplierInvoiceType::PAYMENT])->sum('amount'),
            'returnTotal'   => $supplier->invoices()->where('type', SupplierInvoiceType::RETURN)->sum('amount'),
            ...$this->invoiceModalViewData($this->supplierId),
        ])->layout('layouts.admin.admin');
    }
}
