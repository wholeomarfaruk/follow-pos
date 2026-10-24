@php
    $isOrder = $type === 'order';
    $title = $isOrder ? 'Purchase Order' : 'Receiving Checklist';
    $money = fn ($n) => $currency . number_format((float) $n, 2);
    $qty = fn ($n) => \App\Support\Units::text((float) $n); // "500 m", plus the converted unit when it's on
    $companyLine = collect([$company['address'], $company['city'], $company['state'], $company['postal_code']])->filter()->implode(', ');
    $supplier = $order->supplier;
@endphp
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} — {{ $order->order_number }}</title>
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

    <div class="no-print max-w-3xl mx-auto mb-4 flex items-center justify-between gap-3">
        <div class="flex gap-2 text-sm">
            <a href="{{ route('admin.purchase.orders.print', [$order->id, 'order']) }}"
                class="px-3 py-1.5 rounded-lg {{ $isOrder ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200' }}">Purchase Order</a>
            <a href="{{ route('admin.purchase.orders.print', [$order->id, 'checklist']) }}"
                class="px-3 py-1.5 rounded-lg {{ ! $isOrder ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 border border-gray-200' }}">Receiving Checklist</a>
        </div>
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
                    @if($isOrder && $company['tax_number'])<p class="text-xs text-gray-500">VAT/Tax No: {{ $company['tax_number'] }}</p>@endif
                </div>
            </div>
            <div class="text-right shrink-0">
                <p class="text-2xl font-bold text-gray-900 uppercase tracking-wide">{{ $title }}</p>
                <p class="text-sm font-mono text-gray-600 mt-1">{{ $order->order_number }}</p>
                <p class="text-xs text-gray-500 capitalize mt-1">{{ $order->status }}</p>
            </div>
        </div>

        {{-- Supplier / dates --}}
        <div class="px-8 py-5 grid grid-cols-2 gap-6 border-b border-gray-100">
            <div>
                <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Supplier</p>
                <p class="text-sm font-medium text-gray-900">{{ $supplier->company_name ?: $supplier->name }}</p>
                @if($supplier->company_name)<p class="text-sm text-gray-600">Attn: {{ $supplier->name }}</p>@endif
                @if($supplier->phone)<p class="text-sm text-gray-600">{{ $supplier->phone }}</p>@endif
                @if($supplier->email)<p class="text-sm text-gray-600">{{ $supplier->email }}</p>@endif
                @if($supplier->address)<p class="text-sm text-gray-600 whitespace-pre-line">{{ $supplier->address }}</p>@endif
                <p class="text-xs text-gray-400 font-mono mt-1">{{ $supplier->code }}</p>
            </div>
            <div>
                <dl class="text-sm space-y-1">
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Order Date</dt><dd class="text-gray-900">{{ $order->order_date?->format('d M Y') ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Delivery By</dt><dd class="text-gray-900">{{ $order->deadline?->format('d M Y') ?? '—' }}</dd></div>
                    @unless($isOrder)
                        <div class="flex justify-between gap-4"><dt class="text-gray-500">Received So Far</dt><dd class="text-gray-900">{{ $qty($order->received_quantity) }} / {{ $qty($order->ordered_quantity) }}</dd></div>
                        <div class="flex justify-between gap-4"><dt class="text-gray-500">Received On</dt><dd class="text-gray-400">____ / ____ / ________</dd></div>
                    @endunless
                </dl>
            </div>
        </div>

        {{-- Items --}}
        <div class="px-8 py-5">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wide text-gray-400 border-b border-gray-200">
                        <th class="py-2 pr-2 w-8">#</th>
                        <th class="py-2 pr-2">Item</th>
                        @if($isOrder)
                            <th class="py-2 px-2 text-right">Qty</th>
                            <th class="py-2 px-2 text-right">Unit Price</th>
                            <th class="py-2 pl-2 text-right">Total</th>
                        @else
                            <th class="py-2 px-2 text-right">Ordered</th>
                            <th class="py-2 px-2 text-right">Received</th>
                            <th class="py-2 px-2 text-right">Pending</th>
                            <th class="py-2 px-2 text-center w-24">Counted</th>
                            <th class="py-2 pl-2 text-center w-12">OK</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $item)
                        <tr class="border-b border-gray-100 align-top">
                            <td class="py-2.5 pr-2 text-gray-400">{{ $loop->iteration }}</td>
                            <td class="py-2.5 pr-2">
                                <p class="text-gray-900">{{ $item->product->name ?? 'Unknown product' }}</p>
                                @php($options = $item->variant?->options_map ?? [])
                                @php($sku = $item->variant->sku ?? $item->product->code ?? null)
                                @if($options || $sku)
                                    <p class="text-xs text-gray-500">{{ implode(' · ', $options) }}{{ $options && $sku ? ' · ' : '' }}{{ $sku ? 'SKU ' . $sku : '' }}</p>
                                @endif
                            </td>
                            @if($isOrder)
                                <td class="py-2.5 px-2 text-right">{{ $qty($item->quantity) }}</td>
                                <td class="py-2.5 px-2 text-right">{{ $item->unit_price !== null ? $money($item->unit_price) : '—' }}</td>
                                <td class="py-2.5 pl-2 text-right font-medium">{{ $item->total_amount !== null ? $money($item->total_amount) : '—' }}</td>
                            @else
                                <td class="py-2.5 px-2 text-right">{{ $qty($item->quantity) }}</td>
                                <td class="py-2.5 px-2 text-right">{{ $qty($item->received_quantity) }}</td>
                                <td class="py-2.5 px-2 text-right font-medium">{{ $qty(max(0, (float) $item->quantity - $item->received_quantity)) }}</td>
                                <td class="py-2.5 px-2 text-center"><span class="inline-block w-16 border-b border-gray-300">&nbsp;</span></td>
                                <td class="py-2.5 pl-2 text-center"><span class="inline-block w-4 h-4 border border-gray-400 rounded-sm"></span></td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($isOrder)
                <div class="flex justify-end mt-4">
                    <dl class="w-72 text-sm space-y-1.5">
                        <div class="flex justify-between"><dt class="text-gray-500">Total Quantity</dt><dd>{{ $qty($order->ordered_quantity) }}</dd></div>
                        <div class="flex justify-between pt-2 mt-1 border-t border-gray-200 text-base font-semibold text-gray-900"><dt>Order Total</dt><dd>{{ $money($order->ordered_amount) }}</dd></div>
                    </dl>
                </div>
            @else
                <p class="text-sm text-gray-600 mt-4">Total ordered: <span class="font-medium text-gray-900">{{ $qty($order->ordered_quantity) }}</span></p>
            @endif
        </div>

        @if($order->notes)
            <div class="px-8 py-4 border-t border-gray-100">
                <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wide mb-1">Notes</p>
                <p class="text-sm text-gray-700 whitespace-pre-line">{{ $order->notes }}</p>
            </div>
        @endif

        {{-- Signatures --}}
        <div class="px-8 pt-12 pb-6 grid grid-cols-3 gap-8 text-center text-xs text-gray-500">
            @foreach($isOrder ? ['Prepared By', 'Approved By', 'Supplier Acceptance'] : ['Received By', 'Checked By', 'Delivered By'] as $role)
                <div>
                    <div class="border-t border-gray-300 pt-1.5">{{ $role }}</div>
                </div>
            @endforeach
        </div>

        <div class="px-8 py-4 border-t border-gray-100 text-center text-xs text-gray-400">
            {{ $isOrder ? 'Please quote ' . $order->order_number . ' on your delivery challan and invoice.' : 'Count every item before signing. Report shortages or damage on this sheet.' }}
        </div>
    </div>
</body>

</html>
