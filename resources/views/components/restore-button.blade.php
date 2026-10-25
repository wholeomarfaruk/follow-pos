{{-- Restore a soft-deleted row (admin lists, "Deleted" filter). `action` is the Livewire call, e.g. "restoreCustomer(12)". --}}
@props(['action', 'label' => 'Restore'])

<button type="button" wire:click="{{ $action }}" wire:loading.attr="disabled"
    {{ $attributes->class('inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-indigo-600 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition disabled:opacity-50') }}>
    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/>
    </svg>
    {{ $label }}
</button>
