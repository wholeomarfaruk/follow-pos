<div x-data x-init="$store.pageName = { name: 'Supplier Ledger', slug: 'purchase-supplier-ledger' }">

    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.purchase.suppliers') }}" wire:navigate
                class="w-9 h-9 flex items-center justify-center rounded-lg border border-gray-200 text-gray-400 hover:bg-gray-50 hover:text-gray-600 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/>
                </svg>
            </a>
            <div>
                <h1 class="text-lg font-semibold text-gray-900">{{ $supplier->name }}</h1>
                <p class="text-xs text-gray-400 font-mono">{{ $supplier->code }}</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.purchase.orders.create') }}?supplier={{ $supplier->id }}" wire:navigate
                class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-indigo-600 bg-indigo-50 rounded-xl hover:bg-indigo-100 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                New Purchase Order
            </a>
            <button wire:click="openInvoiceModal" type="button"
                class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition shadow-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
                Record Transaction
            </button>
        </div>
    </div>

    {{-- Stat tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs text-gray-400">Current Balance</p>
            <p class="text-xl font-semibold {{ $supplier->balance > 0 ? 'text-red-500' : 'text-emerald-600' }} mt-0.5">{{ number_format($supplier->balance, 2) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs text-gray-400">Total Purchased</p>
            <p class="text-xl font-semibold text-gray-800 mt-0.5">{{ number_format($purchaseTotal, 2) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs text-gray-400">Total Paid</p>
            <p class="text-xl font-semibold text-gray-800 mt-0.5">{{ number_format($paidTotal, 2) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs text-gray-400">Returned</p>
            <p class="text-xl font-semibold text-gray-800 mt-0.5">{{ number_format($returnTotal, 2) }}</p>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="flex items-center gap-1 mb-4 border-b border-gray-200">
        @foreach(['transactions' => 'Transactions', 'orders' => 'Purchase Orders'] as $key => $label)
            <button wire:click="setTab('{{ $key }}')" type="button"
                class="px-4 py-2.5 text-sm font-medium -mb-px border-b-2 transition {{ $tab === $key ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                {{ $label }}
                @if($key === 'orders' && $openOrderCount)
                    <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded-full text-[11px] font-medium bg-amber-50 text-amber-600">{{ $openOrderCount }} open</span>
                @endif
            </button>
        @endforeach
    </div>

    @if($tab === 'orders')
        @include('livewire.admin.purchase.partials.supplier-orders', ['orders' => $supplierOrders])
    @else
    {{-- Ledger --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/40">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide w-16">#</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Type</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Invoice No.</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-500 uppercase tracking-wide">Items</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Amount</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Balance</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($invoices as $invoice)
                        <tr class="hover:bg-gray-50/50 transition">
                            <td class="px-5 py-3">
                                <span class="text-sm text-gray-400 font-mono tabular-nums">#{{ str_pad($invoice->serial_number, 6, '0', STR_PAD_LEFT) }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-1.5">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium {{ $invoice->type->badgeClass() }}">
                                        {{ $invoice->type->label() }}
                                    </span>
                                    @if($invoice->is_adjusted)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-50 text-amber-600">Adjusted</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-sm text-gray-600">{{ $invoice->invoice_number ?? '—' }}</span>
                                    @if(($invoice->documents ?? collect())->isNotEmpty())
                                        <div class="relative" x-data="{ open: false }">
                                            <button type="button" @click="open = !open" @click.outside="open = false"
                                                class="inline-flex items-center gap-0.5 text-gray-400 hover:text-indigo-600 transition" title="{{ $invoice->documents->count() }} document(s)">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                                                </svg>
                                                <span class="text-[11px]">{{ $invoice->documents->count() }}</span>
                                            </button>
                                            <div x-show="open" x-cloak x-transition
                                                class="absolute z-20 top-full left-0 mt-1 w-48 rounded-lg border border-gray-200 bg-white shadow-lg py-1">
                                                @foreach($invoice->documents as $document)
                                                    <a href="{{ file_path($document->id) }}" download="{{ $document->name }}" target="_blank"
                                                        class="flex items-center gap-2 px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50 transition">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                                        </svg>
                                                        <span class="truncate">{{ $document->name }}</span>
                                                    </a>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                @if($invoice->purchase_order_id)
                                    <a href="{{ route('admin.purchase.orders.edit', $invoice->purchase_order_id) }}" wire:navigate
                                        class="inline-flex items-center mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-mono font-medium bg-indigo-50 text-indigo-600 hover:bg-indigo-100">
                                        {{ $invoice->purchaseOrder->order_number ?? 'PO' }}
                                    </a>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                <span class="text-sm text-gray-500">{{ $invoice->invoice_date?->format('d M, Y') ?? '—' }}</span>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <span class="text-sm text-gray-500">{{ $invoice->items_count ?: '—' }}</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <span class="text-sm font-semibold tabular-nums {{ $invoice->type->isDebit() ? 'text-red-500' : 'text-emerald-600' }}">
                                    {{ $invoice->type->isDebit() ? '+' : '−' }}{{ number_format($invoice->amount, 2) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                @php $isDue = $invoice->balance_after > 0; @endphp
                                <span class="text-sm font-medium tabular-nums {{ $isDue ? 'text-red-500' : 'text-emerald-600' }}">
                                    {{ number_format(abs($invoice->balance_after), 2) }}
                                </span>
                                <span class="block text-[11px] {{ $isDue ? 'text-red-400' : 'text-emerald-500' }}">{{ $isDue ? 'Due' : 'Advance' }}</span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <a href="{{ route('admin.purchase.invoices.print', $invoice->id) }}" target="_blank"
                                        class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:bg-indigo-50 hover:text-indigo-600 transition" title="Print">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z"/>
                                        </svg>
                                    </a>
                                    <button type="button" wire:click="openEditInvoiceModal({{ $invoice->id }})"
                                        class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:bg-indigo-50 hover:text-indigo-600 transition">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/>
                                        </svg>
                                    </button>
                                    @if($invoice->is_latest_serial)
                                        <button type="button" x-data
                                            @click="Swal.fire({
                                                title: 'Delete transaction?',
                                                text: 'This will remove #{{ $invoice->serial_number }} and adjust the supplier balance.',
                                                icon: 'warning',
                                                showCancelButton: true,
                                                confirmButtonColor: '#ef4444',
                                                confirmButtonText: 'Delete'
                                            }).then(r => { if (r.isConfirmed) $wire.deleteInvoice({{ $invoice->id }}) })"
                                            class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-500 transition">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                                            </svg>
                                        </button>
                                    @else
                                        <span class="w-8 h-8 inline-flex items-center justify-center rounded-lg text-gray-300 cursor-not-allowed" title="Delete newer invoices first">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                                            </svg>
                                        </span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-16 text-center">
                                <p class="text-sm font-semibold text-gray-700">No transactions yet</p>
                                <p class="text-xs text-gray-400 mt-0.5">Record a purchase, advance, payment, or return to start the ledger</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($invoices->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">
                {{ $invoices->links() }}
            </div>
        @endif
    </div>
    @endif

    {{-- Record Transaction Modal --}}
    @include('livewire.admin.purchase.partials.invoice-modal', [
        'pickSupplier' => false,
        'createTitle'  => 'Record Transaction',
        'editTitle'    => 'Edit Transaction',
        'saveLabel'    => 'Save Transaction',
        'updateLabel'  => 'Update Transaction',
        'subtitle'     => $supplier->name,
    ])

</div>
