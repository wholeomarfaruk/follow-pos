{{--
    Once per layout: the Alpine store <x-qty> reads, and the small menu that
    switches every quantity on the page to another unit of the same kind.
    The pick is per viewer (localStorage, keyed by stock unit); "Settings"
    goes back to Site Settings › Localization. data-navigate-once: the store
    must register only once per full page load.
--}}
@php $qtyConfig = \App\Support\Units::config(); @endphp
<script data-navigate-once>
    document.addEventListener('alpine:init', () => {
        const cfg = @json($qtyConfig);
        const key = 'qty_unit_' + cfg.unit;
        let saved = null;
        try { saved = localStorage.getItem(key); } catch (e) {}

        Alpine.store('units', {
            cfg,
            // null = use the Settings unit · 'none' = stock unit only · a unit key = show that
            pick: saved === 'none' || (saved && cfg.units[saved]) ? saved : null,
            open: false,
            x: 0,
            y: 0,
            get second() {
                if (this.pick === 'none') return null;
                return this.pick ?? cfg.converted;
            },
            num(v, dp) {
                const n = Number((Number(v) || 0).toFixed(dp));
                return n.toLocaleString('en-US', { maximumFractionDigits: dp });
            },
            text(v, signed = false) {
                let t = (signed && v > 0 ? '+' : '') + this.num(v, 3) + ' ' + cfg.short;
                const s = this.second;
                if (s && s !== cfg.unit && cfg.units[s]) {
                    t += ' · ' + this.num(v * cfg.units[cfg.unit][1] / cfg.units[s][1], 2) + ' ' + cfg.units[s][0];
                }
                return t;
            },
            menu(e) {
                const r = e.currentTarget.getBoundingClientRect();
                this.x = Math.min(r.left, window.innerWidth - 180);
                this.y = r.bottom + 4;
                this.open = true;
            },
            choose(unit) {
                this.pick = unit;
                this.open = false;
                try { unit === null ? localStorage.removeItem(key) : localStorage.setItem(key, unit); } catch (e) {}
            },
        });
    });
</script>

<div x-data x-cloak x-show="$store.units && $store.units.open" @click.outside="$store.units.open = false"
    @keydown.escape.window="$store.units.open = false" @scroll.window="$store.units.open = false"
    :style="$store.units ? `position:fixed;left:${$store.units.x}px;top:${$store.units.y}px;min-width:170px` : ''"
    class="z-50 rounded-lg border border-gray-200 bg-white py-1 text-sm text-gray-700 shadow-lg">
    <p class="px-3 py-1.5 text-xs text-gray-400">এভাবে দেখাও</p>
    @foreach ($qtyConfig['units'] as $unitKey => [$unitShort])
        @continue($unitKey === $qtyConfig['unit'])
        <button type="button" @click="$store.units.choose('{{ $unitKey }}')"
            class="flex w-full items-center justify-between px-3 py-1.5 text-left hover:bg-gray-50"
            :class="$store.units && $store.units.second === '{{ $unitKey }}' ? 'font-semibold text-indigo-600' : ''">
            {{ $qtyConfig['short'] }} + {{ $unitShort }}
        </button>
    @endforeach
    <button type="button" @click="$store.units.choose('none')"
        class="flex w-full px-3 py-1.5 text-left hover:bg-gray-50"
        :class="$store.units && $store.units.second === null ? 'font-semibold text-indigo-600' : ''">শুধু {{ $qtyConfig['short'] }}</button>
    <button type="button" @click="$store.units.choose(null)"
        class="flex w-full border-t border-gray-100 px-3 py-1.5 text-left text-xs text-gray-500 hover:bg-gray-50">Settings অনুযায়ী</button>
</div>
