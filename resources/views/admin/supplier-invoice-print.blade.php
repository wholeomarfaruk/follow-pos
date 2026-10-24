@php
    $title = $invoice->type->documentTitle();
    $money = fn ($n) => $currency . number_format((float) $n, 2);
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
    $companyLine = collect([$company['address'], $company['city'], $company['state'], $company['postal_code']])->filter()->implode(', ');
    $supplier = $invoice->supplier;
    $serial = '#' . str_pad((string) $invoice->serial_number, 6, '0', STR_PAD_LEFT);
    $bill = $invoice->bill;
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} {{ $serial }} — {{ $supplier->name }}</title>
    @if($company['favicon'])
        <link rel="icon" href="{{ file_path($company['favicon']) }}">
    @endif
    @vite(['resources/css/admin.css'])
    <style>
        @media print {
            .no-print { display: none !important; }
            body { padding: 0 !important; background: #fff !important; }
            .print-card { box-shadow: none !important; border: none !important; }
        }
        @page { margin: 1.2cm; }
    </style>
</head>

<body class="bg-gray-100 p-6 sm:p-10 antialiased text-gray-800">

    <div class="no-print max-w-3xl mx-auto mb-4 flex items-center justify-end gap-3">
        <button onclick="window.print()"
            class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-xl hover:bg-indigo-700 transition shadow-sm">
            Print
        </button>
    </div>

    <div class="print-card max-w-3xl mx-auto bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

        {{-- Header --}}
        <div class="px-8 py-6 border-b border-gray-100 flex items-start justify-between gap-6">
            <div class="flex items-center gap-4">
                @if($company['logo'])
                    <img src="{{ file_path($company['logo']) }}" alt="{{ $company['name'] }}" class="h-14 object-contain">
                @endif
                <div>
                    <p class="text-lg font-semibold text-gray-900">{{ $company['name'] }}</p>
                    @if($companyLine)<p class="text-xs text-gray-500">{{ $companyLine }}</p>@endif
                    <p class="text-xs text-gray-500">
                        {{ collect([$company['phone'] ?: $company['mobile'], $company['email'], $company['website']])->filter()->implode(' · ') }}
                    </p>
                </div>
            </div>
            <div class="text-right shrink-0">
                <p class="text-2xl font-bold text-gray-900 uppercase tracking-wide">{{ $title }}</p>
                <p class="text-sm font-mono text-gray-600 mt-1">{{ $serial }}</p>
                @if($invoice->invoice_number)
                    <p class="text-xs text-gray-500">Supplier Ref: {{ $invoice->invoice_number }}</p>
                @endif
                <p class="text-xs text-gray-500">{{ $invoice->invoice_date?->format('d M Y') ?? $invoice->created_at->format('d M Y') }}</p>
                @if($invoice->is_adjusted)
                    <p class="text-xs text-amber-600 mt-1">Adjusted</p>
                @endif
            </div>
        </div>

        {{-- Supplier / references --}}
        <div class="px-8 py-5 grid grid-cols-2 gap-6 border-b border-gray-100">
            <div>
                <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Supplier</p>
                <p class="text-sm font-medium text-gray-900">{{ $supplier->company_name ?: $supplier->name }}</p>
                @if($supplier->company_name)<p class="text-sm text-gray-600">{{ $supplier->name }}</p>@endif
                @if($supplier->phone)<p class="text-sm text-gray-600">{{ $supplier->phone }}</p>@endif
                @if($supplier->address)<p class="text-sm text-gray-600 whitespace-pre-line">{{ $supplier->address }}</p>@endif
                <p class="text-xs text-gray-400 font-mono mt-1">{{ $supplier->code }}</p>
            </div>
            <div>
                <dl class="text-sm space-y-1">
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Type</dt><dd class="text-gray-900">{{ $invoice->type->label() }}</dd></div>
                    @if($invoice->purchaseOrder)
                        <div class="flex justify-between gap-4"><dt class="text-gray-500">Purchase Order</dt><dd class="font-mono text-gray-900">{{ $invoice->purchaseOrder->order_number }}</dd></div>
                    @endif
                    @if($bill)
                        <div class="flex justify-between gap-4"><dt class="text-gray-500">Bill Status</dt><dd class="text-gray-900 capitalize">{{ $bill->status }}</dd></div>
                        @if($bill->due_date)
                            <div class="flex justify-between gap-4"><dt class="text-gray-500">Due Date</dt><dd class="text-gray-900">{{ $bill->due_date->format('d M Y') }}</dd></div>
                        @endif
                    @endif
                </dl>
            </div>
        </div>

        {{-- Items / amount --}}
        <div class="px-8 py-5">
            @if($invoice->items->isNotEmpty())
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wide text-gray-400 border-b border-gray-200">
                            <th class="py-2 pr-2 w-8">#</th>
                            <th class="py-2 pr-2">Item</th>
                            <th class="py-2 px-2 text-right">Qty</th>
                            <th class="py-2 px-2 text-right">Unit Price</th>
                            <th class="py-2 pl-2 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoice->items as $item)
                            <tr class="border-b border-gray-100 align-top">
                                <td class="py-2.5 pr-2 text-gray-400">{{ $loop->iteration }}</td>
                                <td class="py-2.5 pr-2 text-gray-900">{{ $item->name }}</td>
                                <td class="py-2.5 px-2 text-right">{{ $item->quantity !== null ? $qty($item->quantity) : '—' }}</td>
                                <td class="py-2.5 px-2 text-right">{{ $item->unit_price !== null ? $money($item->unit_price) : '—' }}</td>
                                <td class="py-2.5 pl-2 text-right font-medium">{{ $money($item->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <div class="flex justify-end {{ $invoice->items->isNotEmpty() ? 'mt-4' : '' }}">
                <dl class="w-72 text-sm space-y-1.5">
                    <div class="flex justify-between text-base font-semibold text-gray-900"><dt>{{ $invoice->type->isDebit() ? 'Total' : 'Amount' }}</dt><dd>{{ $money($invoice->amount) }}</dd></div>
                    @if($bill)
                        <div class="flex justify-between"><dt class="text-gray-500">Paid</dt><dd>{{ $money($bill->amount_allocated) }}</dd></div>
                        <div class="flex justify-between font-semibold"><dt>Due</dt><dd>{{ $money($bill->amountDue()) }}</dd></div>
                    @endif
                    <div class="flex justify-between pt-2 mt-1 border-t border-gray-200">
                        <dt class="text-gray-500">Supplier balance after this</dt>
                        <dd class="font-medium">{{ $money(abs($balanceAfter)) }} {{ $balanceAfter > 0 ? 'payable' : ($balanceAfter < 0 ? 'advance' : '') }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        @if($invoice->notes)
            <div class="px-8 py-4 border-t border-gray-100">
                <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Notes</p>
                <p class="text-sm text-gray-700 whitespace-pre-line">{{ $invoice->notes }}</p>
            </div>
        @endif

        {{-- Signatures --}}
        <div class="px-8 pt-12 pb-6 grid grid-cols-3 gap-8 text-center text-xs text-gray-500">
            @foreach(['Prepared By', 'Approved By', 'Supplier / Receiver'] as $role)
                <div>
                    <div class="border-t border-gray-300 pt-1.5">{{ $role }}</div>
                </div>
            @endforeach
        </div>
    </div>
</body>

</html>
