{{--
    A stock quantity in the stock unit, plus the converted unit when it's on
    (Site Settings › Localization) — "5 m · 5.47 yd". Click it to see it in
    another unit of the same kind (store + menu: <x-qty-units />). The server
    text is the fallback where Alpine isn't loaded (print pages).
--}}
@props(['value' => 0, 'signed' => false])
@php
    $qtyValue = (float) $value;
    $qtyText = ($signed && $qtyValue > 0 ? '+' : '') . \App\Support\Units::text($qtyValue);
    $qtySwitchable = count(\App\Support\Units::config()['units']) > 1;
@endphp
<span {{ $attributes->merge(['class' => 'whitespace-nowrap' . ($qtySwitchable ? ' cursor-pointer' : '')]) }}
    wire:key="{{ \App\Support\Units::domKey($qtyValue, (bool) $signed) }}"
    x-data="{ v: {{ $qtyValue }} }"
    x-text="$store.units ? $store.units.text(v, {{ $signed ? 'true' : 'false' }}) : @js($qtyText)"
    @if ($qtySwitchable) title="অন্য unit-এ দেখতে click করুন" @click.stop="$store.units && $store.units.menu($event)" @endif>{{ $qtyText }}</span>
