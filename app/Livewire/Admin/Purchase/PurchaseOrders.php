<?php

namespace App\Livewire\Admin\Purchase;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Purchase\PurchaseOrderProgress;
use Livewire\Component;
use Livewire\WithPagination;

class PurchaseOrders extends Component
{
    use WithPagination;

    public string $search        = '';
    public string $filterStatus  = '';
    public string $filterSupplier = '';

    protected string $paginationTheme = 'tailwind';

    public function updatingSearch(): void         { $this->resetPage(); }
    public function updatingFilterStatus(): void   { $this->resetPage(); }
    public function updatingFilterSupplier(): void { $this->resetPage(); }

    public function cancelOrder(int $id): void
    {
        $order = PurchaseOrder::findOrFail($id);

        if ($order->status !== 'pending') {
            $this->dispatch('toast', ['type' => 'error', 'message' => "Only a pending order can be cancelled — {$order->order_number} is {$order->status}."]);
            return;
        }

        $order->update(['status' => 'cancelled']);

        activity('purchase')
            ->causedBy(auth()->user())
            ->performedOn($order)
            ->event('updated')
            ->log("Purchase order \"{$order->order_number}\" was cancelled");

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Purchase order cancelled']);
    }

    public function deleteOrder(int $id): void
    {
        $order = PurchaseOrder::with('items')->withCount(['invoices', 'receivings'])->findOrFail($id);
        $number = $order->order_number;

        // Its GRNs, batches and invoices point at this order — keep it (and
        // cancel instead) once anything was received or billed against it.
        // received_quantity also covers receipts made before GRNs existed
        // (stock movements against the PO line, with no receiving row).
        app(PurchaseOrderProgress::class)->attach(collect([$order]));

        if ($order->invoices_count > 0 || $order->receivings_count > 0
            || $order->received_quantity > 0
            || $order->items()->whereHas('invoiceItems')->exists()
            || $order->items()->whereHas('receivingItems')->exists()) {
            $this->dispatch('toast', ['type' => 'error', 'message' => "{$number} already has receivings or invoices — cancel it instead of deleting."]);
            return;
        }

        $order->delete();

        activity('purchase')
            ->causedBy(auth()->user())
            ->withProperties(['order_number' => $number])
            ->event('deleted')
            ->log("Purchase order \"{$number}\" was deleted");

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Purchase order deleted']);
    }

    public function render(): mixed
    {
        $orders = PurchaseOrder::query()
            ->withCount('items')
            ->with(['supplier', 'items'])
            ->when($this->search, fn($q) => $q->where(fn($s) => $s
                ->where('order_number', 'like', "%{$this->search}%")
                ->orWhereHas('supplier', fn($sup) => $sup->where('name', 'like', "%{$this->search}%"))
            ))
            ->when($this->filterStatus !== '', fn($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterSupplier !== '', fn($q) => $q->where('supplier_id', $this->filterSupplier))
            ->orderByDesc('id')
            ->paginate(15);

        app(PurchaseOrderProgress::class)->attach($orders->getCollection());

        return view('livewire.admin.purchase.purchase-orders', [
            'orders'          => $orders,
            'suppliers'       => Supplier::orderBy('name')->get(['id', 'name']),
            'totalCount'      => PurchaseOrder::count(),
            'pendingCount'    => PurchaseOrder::where('status', 'pending')->count(),
            'receivedCount'   => PurchaseOrder::where('status', 'received')->count(),
        ])->layout('layouts.admin.admin');
    }
}
