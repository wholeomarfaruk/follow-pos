{{--
    Supplier Ledger › Purchase Orders tab.

    @param \Illuminate\Pagination\LengthAwarePaginator $orders  POs with progress attached (PurchaseOrderProgress)
--}}
@php
    $statusStyles = [
        'pending'   => 'bg-amber-50 text-amber-600',
        'received'  => 'bg-emerald-50 text-emerald-600',
        'cancelled' => 'bg-red-50 text-red-500',
    ];
@endphp
<div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50/40">
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Order</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Total</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide w-40">Received</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Billed</th>
                    <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($orders as $order)
                    <tr class="hover:bg-gray-50/50 transition" wire:key="supplier-po-{{ $order->id }}">
                        <td class="px-5 py-3">
                            <a href="{{ route('admin.purchase.orders.edit', $order->id) }}" wire:navigate class="text-sm font-medium text-gray-800 font-mono hover:text-indigo-600 hover:underline">{{ $order->order_number }}</a>
                            <span class="block text-xs text-gray-400">{{ $order->items->count() }} {{ $order->items->count() === 1 ? 'item' : 'items' }}</span>
                        </td>
                        <td class="px-5 py-3">
                            <span class="text-sm text-gray-500">{{ $order->order_date?->format('d M, Y') ?? '—' }}</span>
                            @if($order->deadline)
                                <span class="block text-xs text-gray-400">Due {{ $order->deadline->format('d M, Y') }}</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right">
                            <span class="text-sm font-medium text-gray-800 tabular-nums">{{ number_format($order->ordered_amount, 2) }}</span>
                        </td>
                        <td class="px-5 py-3">
                            @include('livewire.admin.purchase.partials.received-progress', ['order' => $order])
                        </td>
                        <td class="px-5 py-3 text-right">
                            <span class="text-sm tabular-nums {{ $order->invoiced_amount + 0.01 >= $order->ordered_amount && $order->ordered_amount > 0 ? 'text-emerald-600 font-medium' : 'text-gray-600' }}">{{ number_format($order->invoiced_amount, 2) }}</span>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium capitalize {{ $statusStyles[$order->status] ?? 'bg-gray-100 text-gray-500' }}">{{ $order->status }}</span>
                        </td>
                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1">
                                @if(! $order->isCancelled() && $order->items->contains(fn ($item) => $item->invoiced_quantity + 0.0005 < (float) $item->quantity))
                                    <button wire:click="openInvoiceModalForPurchaseOrder({{ $order->id }})" type="button"
                                        class="inline-flex items-center px-2.5 py-1.5 text-xs font-medium text-indigo-600 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition">
                                        Bill
                                    </button>
                                @endif
                                <a href="{{ route('admin.purchase.orders.print', $order->id) }}" target="_blank" title="Print purchase order"
                                    class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:bg-indigo-50 hover:text-indigo-600 transition">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/>
                                    </svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-16 text-center">
                            <p class="text-sm font-semibold text-gray-700">No purchase orders yet</p>
                            <p class="text-xs text-gray-400 mt-0.5">Create one with New Purchase Order above</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($orders->hasPages())
        <div class="px-5 py-3 border-t border-gray-100">
            {{ $orders->links() }}
        </div>
    @endif
</div>
