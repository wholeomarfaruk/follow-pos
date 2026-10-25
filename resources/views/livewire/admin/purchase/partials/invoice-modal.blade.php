{{--
    Create / edit supplier invoice modal (HandlesSupplierInvoiceModal) —
    shared by the Supplier Invoices list and the Supplier Ledger.

    @param bool        $pickSupplier  show the supplier picker (cross-supplier list) instead of a fixed supplier
    @param string      $createTitle / $editTitle
    @param string      $saveLabel / $updateLabel
    @param string|null $subtitle
--}}
<div x-cloak x-data="{ open: @entangle('invoiceModal') }" x-show="open" x-transition
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog">
    <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden max-h-[90vh] flex flex-col" @click.outside="open = false">

        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 shrink-0">
            <div class="w-8 h-8 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                </svg>
            </div>
            <div class="flex-1">
                <h2 class="text-base font-semibold text-gray-900">{{ $editingInvoiceId ? $editTitle : $createTitle }}</h2>
                @if($subtitle ?? null)
                    <p class="text-xs text-gray-400">{{ $subtitle }}</p>
                @endif
            </div>
            <button wire:click="closeInvoiceModal" type="button" class="w-8 h-8 flex items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="overflow-y-auto px-6 py-5 space-y-5">
            @if($editingIsLocked)
                <div class="flex items-start gap-2.5 rounded-xl bg-amber-50 border border-amber-100 px-4 py-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-amber-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
                    </svg>
                    <p class="text-xs text-amber-700">Only the most recent invoice (and never a payment) can have its type, amount, or items changed. You can still update the invoice number, date, adjusted flag, notes, and documents here.</p>
                </div>
            @endif

            @if($pickSupplier)
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Supplier <span class="text-red-500">*</span></label>
                    <x-searchable-select field="modalSupplierId" :value="$modalSupplierId" :disabled="(bool) $editingInvoiceId"
                        :options="$suppliers->pluck('name', 'id')" placeholder="— Select supplier —" search-placeholder="Search suppliers…" />
                    @error('modalSupplierId') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            @if(! $editingInvoiceId && (! $pickSupplier || $modalSupplierId !== ''))
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Purchase Order (optional)</label>
                    <x-searchable-select wire:key="po-select-{{ $pickSupplier ? $modalSupplierId : 'fixed' }}"
                        field="purchaseOrderId" :value="$purchaseOrderId"
                        :options="$purchaseOrders->mapWithKeys(fn ($po) => [
                            (string) $po->id => $po->order_number . ' (' . $po->status . ') — billed ' . number_format($po->invoiced_total, 2) . ' of ' . number_format($po->total_amount, 2),
                        ])"
                        placeholder="— None — (manual entry) —" search-placeholder="Search purchase orders…" />
                    @error('purchaseOrderId') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-400 mt-1.5">Selecting a PO fills in what's still unbilled on it — fully editable afterwards. Pick the same PO again later for its next partial invoice.</p>
                </div>
            @endif

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Type <span class="text-red-500">*</span></label>
                    <select wire:model.live="invoiceType" @disabled($editingIsLocked || $purchaseOrderId !== '') class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-400">
                        @foreach(\App\Enums\Purchase\SupplierInvoiceType::cases() as $type)
                            {{-- A payment is only ever created new (it's applied to bills then). --}}
                            @continue($editingInvoiceId && $type === \App\Enums\Purchase\SupplierInvoiceType::PAYMENT && $invoiceType !== 'payment')
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('invoiceType') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Invoice No.</label>
                    <input wire:model="invoiceNumber" type="text" placeholder="Supplier's invoice #"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Date</label>
                    <input wire:model="invoiceDate" type="date"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                </div>
            </div>

            @if(in_array($invoiceType, ['purchase', 'return']))
                {{-- Item-based --}}
                @if($invoiceType === 'return')
                    <p class="text-xs text-gray-500 rounded-lg bg-gray-50 px-3 py-2">Goods sent back to the supplier — reduces what you owe them. Accounts: Cr Inventory, Dr Payable against their open bills (any excess becomes a supplier advance).</p>
                @endif
                @unless($editingIsLocked)
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Add Item</label>
                        <div class="relative mb-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/>
                            </svg>
                            <input wire:model.live.debounce.300ms="productSearch" type="text" placeholder="Search product or variant by name / SKU…"
                                class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-gray-300 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        </div>
                        @if($productSearch !== '')
                            <div class="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-40 overflow-y-auto mb-3">
                                @forelse($productOptions as $key => $label)
                                    <button wire:click="addItem('{{ $key }}')" type="button"
                                        class="w-full flex items-center justify-between px-3 py-2 hover:bg-gray-50 transition text-left">
                                        <span class="text-sm text-gray-800">{{ $label }}</span>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                        </svg>
                                    </button>
                                @empty
                                    <p class="px-3 py-2 text-xs text-gray-400">No matching products.</p>
                                @endforelse
                            </div>
                        @endif
                        <button wire:click="addItem" type="button"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-700 transition">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                            </svg>
                            Add custom line item
                        </button>
                    </div>
                @endunless

                @error('items') <p class="text-xs text-red-500">{{ $message }}</p> @enderror

                @if(!empty($items))
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50/60 text-left text-xs text-gray-500 uppercase">
                                    <th class="px-3 py-2">Item</th>
                                    <th class="px-3 py-2 w-20">Qty</th>
                                    <th class="px-3 py-2 w-24">Unit Price</th>
                                    <th class="px-3 py-2 w-24 text-right">Amount</th>
                                    @unless($editingIsLocked)
                                        <th class="px-3 py-2 w-8"></th>
                                    @endunless
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($items as $i => $item)
                                    <tr>
                                        <td class="px-3 py-2">
                                            <input wire:model="items.{{ $i }}.name" type="text" placeholder="Item name" @disabled($editingIsLocked)
                                                class="w-full rounded border border-gray-200 px-2 py-1.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-400">
                                            @if($item['purchase_order_item_id'] !== '')
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-50 text-indigo-500 mt-1">From PO</span>
                                            @endif
                                            @error('items.' . $i . '.name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                                        </td>
                                        <td class="px-3 py-2">
                                            <input wire:model.live="items.{{ $i }}.quantity" type="number" step="0.001" min="0" @disabled($editingIsLocked)
                                                class="w-full rounded border border-gray-200 px-2 py-1.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-400">
                                        </td>
                                        <td class="px-3 py-2">
                                            <input wire:model.live="items.{{ $i }}.unit_price" type="number" step="0.01" min="0" @disabled($editingIsLocked)
                                                class="w-full rounded border border-gray-200 px-2 py-1.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-400">
                                        </td>
                                        <td class="px-3 py-2">
                                            <input wire:model="items.{{ $i }}.amount" type="number" step="0.01" min="0" @disabled($editingIsLocked)
                                                class="w-full rounded border border-gray-200 px-2 py-1.5 text-sm text-right focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-400">
                                            @error('items.' . $i . '.amount') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                                        </td>
                                        @unless($editingIsLocked)
                                            <td class="px-3 py-2 text-right">
                                                <button wire:click="removeItem({{ $i }})" type="button" class="text-gray-400 hover:text-red-500 transition">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                                                    </svg>
                                                </button>
                                            </td>
                                        @endunless
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div class="flex items-center justify-end px-3 py-2.5 bg-gray-50/60 border-t border-gray-100">
                            <span class="text-xs text-gray-500 mr-2">Total:</span>
                            <span class="text-sm font-semibold text-gray-800">{{ number_format($this->itemsTotal, 2) }}</span>
                        </div>
                    </div>
                @endif

                @if($invoiceType === 'purchase' && ! $editingInvoiceId)
                    <div class="rounded-xl border border-gray-200 px-4 py-3">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input wire:model.live="markPaidNow" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm text-gray-700">Mark as paid now</span>
                        </label>
                        @if($markPaidNow)
                            <x-payment-method-field method="paymentMethod" reference="paymentReference" :value="$paymentMethod" class="mt-3" />
                            @if($this->paysByCheque())
                                @include('livewire.admin.purchase.partials.cheque-fields', ['class' => 'mt-3'])
                            @endif
                            <div class="grid grid-cols-2 gap-4 mt-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Amount Paid <span class="text-red-500">*</span></label>
                                    <input wire:model="payNowAmount" type="number" step="0.01" min="0" placeholder="0.00"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                    @error('payNowAmount') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Paid From <span class="text-red-500">*</span></label>
                                    <x-searchable-select field="payNowCashAccountId" :value="$payNowCashAccountId"
                                        :options="$cashAccounts->mapWithKeys(fn ($a) => [(string) $a->id => $a->code . ' — ' . $a->name])"
                                        placeholder="— Select account —" search-placeholder="Search accounts…" />
                                    @error('payNowCashAccountId') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            @elseif($invoiceType === 'payment')
                {{-- Payment: applied against this supplier's open bills --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Amount <span class="text-red-500">*</span></label>
                    <input wire:model="manualAmount" type="number" step="0.01" min="0" placeholder="0.00" @disabled($editingIsLocked)
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-400">
                    @error('manualAmount') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                <x-payment-method-field method="paymentMethod" reference="paymentReference" :value="$paymentMethod" />
                @if($this->paysByCheque())
                    @include('livewire.admin.purchase.partials.cheque-fields')
                @endif

                @unless($editingInvoiceId)
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Paid From <span class="text-red-500">*</span></label>
                        <x-searchable-select field="paymentCashAccountId" :value="$paymentCashAccountId"
                            :options="$cashAccounts->mapWithKeys(fn ($a) => [(string) $a->id => $a->code . ' — ' . $a->name])"
                            placeholder="— Select cash/bank account —" search-placeholder="Search accounts…" />
                        @error('paymentCashAccountId') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    @if($openBills->isNotEmpty())
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Apply to specific bills (optional — oldest-first otherwise)</label>
                            <div class="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-40 overflow-y-auto">
                                @foreach($openBills as $bill)
                                    <label class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer hover:bg-gray-50">
                                        <input type="checkbox" wire:model="paymentBills.{{ $bill->id }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        <span class="flex-1">Bill #{{ $bill->id }}{{ $bill->supplierInvoice ? ' — Purchase #' . str_pad($bill->supplierInvoice->serial_number, 6, '0', STR_PAD_LEFT) : '' }}</span>
                                        <span class="text-gray-500">due {{ number_format($bill->amountDue(), 2) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <p class="text-xs text-gray-400">This supplier has no open bills — the payment will still be recorded and reduce their balance.</p>
                    @endif
                @endunless
            @else
                {{-- Manual amount (advance) --}}
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Amount <span class="text-red-500">*</span></label>
                    <input wire:model="manualAmount" type="number" step="0.01" min="0" placeholder="0.00" @disabled($editingIsLocked)
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50 disabled:text-gray-400">
                    @error('manualAmount') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                @if($invoiceType === 'advance')
                    <x-payment-method-field method="paymentMethod" reference="paymentReference" :value="$paymentMethod" />
                    @if($this->paysByCheque())
                        @include('livewire.admin.purchase.partials.cheque-fields')
                    @endif
                @endif

                @if($invoiceType === 'advance' && ! $editingIsLocked)
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Paid From <span class="text-red-500">*</span></label>
                        <x-searchable-select field="advanceCashAccountId" :value="$advanceCashAccountId"
                            :options="$cashAccounts->mapWithKeys(fn ($a) => [(string) $a->id => $a->code . ' — ' . $a->name])"
                            placeholder="— Select cash/bank account —" search-placeholder="Search accounts…" />
                        <p class="text-xs text-gray-400 mt-1.5">Posts Dr Supplier Advance / Cr this account — it can later be applied against a bill from Accounts &gt; Payables.</p>
                        @error('advanceCashAccountId') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endif
            @endif

            <div>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input wire:model="invoiceIsAdjusted" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <span class="text-sm text-gray-700">Mark as adjusted</span>
                </label>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Notes</label>
                <textarea wire:model="invoiceNotes" rows="2" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"></textarea>
            </div>

            <x-document-picker-field field="documentIds" :value="$documentIds" label="Documents"
                placeholder="Attach invoice copies, receipts, or bills" />
        </div>

        <div class="flex items-center justify-end gap-2 px-6 py-4 border-t border-gray-100 shrink-0">
            <button wire:click="closeInvoiceModal" type="button" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancel</button>
            <button wire:click="saveInvoice" type="button" class="px-5 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition">{{ $editingInvoiceId ? $updateLabel : $saveLabel }}</button>
        </div>
    </div>
</div>
