<?php

namespace App\Services\Purchase;

use App\Enums\Purchase\SupplierInvoiceType;
use App\Models\InventoryStockMovement;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * How far each PO line has got: received (stock movements against the line,
 * net of cancelled receivings — same rule as
 * StockService::receivedQuantityForPurchaseOrderItem()) and billed (lines of
 * "purchase" supplier invoices linked to it). Two grouped queries for a whole
 * page of POs, so lists don't run one query per line.
 */
class PurchaseOrderProgress
{
    /**
     * Sets received_quantity / invoiced_quantity / invoiced_amount on every
     * item of the given orders (items must be loaded), and totals on each
     * order: ordered_quantity, received_quantity, ordered_amount,
     * invoiced_amount, received_percent.
     *
     * @param  Collection<int, PurchaseOrder>  $orders
     */
    public function attach(Collection $orders): void
    {
        $items = $orders->flatMap->items;
        $itemIds = $items->pluck('id');

        $received = $itemIds->isEmpty() ? collect() : InventoryStockMovement::query()
            ->where('reference_type', PurchaseOrderItem::class)
            ->whereIn('reference_id', $itemIds)
            ->whereIn('type', ['purchase', 'receiving_cancelled'])
            ->selectRaw('reference_id, SUM(quantity) as received')
            ->groupBy('reference_id')
            ->pluck('received', 'reference_id');

        $invoiced = $itemIds->isEmpty() ? collect() : DB::table('supplier_invoice_items as sii')
            ->join('supplier_invoices as si', 'si.id', '=', 'sii.supplier_invoice_id')
            ->where('si.type', SupplierInvoiceType::PURCHASE->value)
            ->whereIn('sii.purchase_order_item_id', $itemIds)
            ->groupBy('sii.purchase_order_item_id')
            ->get([
                'sii.purchase_order_item_id',
                DB::raw('COALESCE(SUM(sii.quantity), 0) as quantity'),
                DB::raw('COALESCE(SUM(sii.amount), 0) as amount'),
            ])
            ->keyBy('purchase_order_item_id');

        foreach ($items as $item) {
            $item->received_quantity = max(0.0, (float) ($received[$item->id] ?? 0));
            $item->invoiced_quantity = (float) ($invoiced[$item->id]->quantity ?? 0);
            $item->invoiced_amount = (float) ($invoiced[$item->id]->amount ?? 0);
        }

        foreach ($orders as $order) {
            $order->ordered_quantity = (float) $order->items->sum('quantity');
            $order->received_quantity = (float) $order->items->sum(fn ($item) => min((float) $item->quantity, $item->received_quantity));
            $order->ordered_amount = (float) $order->items->sum('total_amount');
            $order->invoiced_amount = (float) $order->items->sum('invoiced_amount');
            $order->received_percent = $order->ordered_quantity > 0
                ? (int) floor($order->received_quantity / $order->ordered_quantity * 100)
                : 0;
        }
    }
}
