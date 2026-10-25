{{-- Cheque bank + date for a supplier payment by cheque (HandlesSupplierInvoiceModal::paysByCheque()). --}}
<div class="grid grid-cols-2 gap-4 {{ $class ?? '' }}">
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1.5">Bank</label>
        <input wire:model="chequeBank" type="text" maxlength="120" placeholder="যেমন: DBBL"
            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        @error('chequeBank') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-600 mb-1.5">Release date (cheque-এর তারিখ) <span class="text-red-500">*</span></label>
        <input wire:model="chequeDate" type="date"
            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
        @error('chequeDate') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
    </div>
    <p class="col-span-2 text-xs text-indigo-700 bg-indigo-50 rounded-lg px-3 py-2">Cheque-টা Accounts › Cheques-এ রাখা হবে। Release-এর ২ দিন আগে থেকে reminder যাবে; cheque clear হলে {{ $invoiceType === 'advance' ? 'advance বসবে' : 'payment হবে, তখনই supplier-এর দেনা কমবে' }}।</p>
</div>
