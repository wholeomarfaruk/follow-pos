{{--
    Received-quantity progress bar for one PO.

    @param \App\Models\PurchaseOrder $order  with PurchaseOrderProgress attached
--}}
<div class="flex items-center gap-2 min-w-[120px]">
    <div class="flex-1 h-1.5 rounded-full bg-gray-100 overflow-hidden">
        <div class="h-full rounded-full {{ $order->received_percent >= 100 ? 'bg-emerald-500' : 'bg-indigo-500' }}" style="width: {{ min(100, $order->received_percent) }}%"></div>
    </div>
    <span class="text-xs tabular-nums {{ $order->received_percent >= 100 ? 'text-emerald-600 font-medium' : 'text-gray-500' }}">{{ $order->received_percent }}%</span>
</div>
