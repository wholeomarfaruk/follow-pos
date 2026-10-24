{{-- Pill tab bar for a section's pages. Pass $tabs = [route name => label] (or [route name => [label, badge]]). --}}
@php $currentRoute = Route::currentRouteName(); @endphp

<div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-2 flex gap-1 overflow-x-auto scrollbar-none mb-6">
    @foreach ($tabs as $routeName => $tab)
        @php
            [$label, $badge] = is_array($tab) ? $tab + [null, null] : [$tab, null];
            $active = $currentRoute === $routeName || str_starts_with((string) $currentRoute, $routeName . '.');
        @endphp
        <a href="{{ route($routeName) }}"
            class="shrink-0 flex items-center gap-2 px-3 py-2.5 rounded-xl text-sm font-medium whitespace-nowrap transition-all
                {{ $active ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800' }}">
            {{ $label }}
            @if ($badge)
                <span class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-[11px] font-semibold {{ $active ? 'bg-white/20 text-white' : 'bg-rose-100 text-rose-600' }}">{{ $badge }}</span>
            @endif
        </a>
    @endforeach
</div>
