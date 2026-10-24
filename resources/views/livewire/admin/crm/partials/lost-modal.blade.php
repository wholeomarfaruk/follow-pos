{{-- Why was the lead lost? Needs lostModal / lostReason / confirmLost() on the component. --}}
<div x-cloak x-data="{ open: @entangle('lostModal') }" x-show="open" x-transition
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden" @click.outside="open = false">
        <div class="px-6 py-4 border-b border-gray-100">
            <h2 class="text-base font-semibold text-gray-900">Mark as lost</h2>
            <p class="text-xs text-gray-500 mt-0.5">The reason shows up in CRM reports, so pick the closest one.</p>
        </div>
        <form wire:submit.prevent="confirmLost" class="px-6 py-5 space-y-4">
            <div class="flex flex-wrap gap-2">
                @foreach (['Price too high', 'Bought elsewhere', 'Not interested', 'No response', 'Wrong number', 'Out of stock'] as $r)
                    <button type="button" wire:click="$set('lostReason', '{{ $r }}')"
                        class="px-3 py-1.5 rounded-full text-xs font-medium border transition {{ $lostReason === $r ? 'bg-rose-600 text-white border-rose-600' : 'bg-white text-gray-600 border-gray-300 hover:border-rose-300' }}">{{ $r }}</button>
                @endforeach
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Reason <span class="text-red-500">*</span></label>
                <input wire:model="lostReason" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-rose-500 focus:ring-1 focus:ring-rose-500">
                @error('lostReason') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100">
                <button @click="open = false" type="button" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-medium text-white bg-rose-600 rounded-lg hover:bg-rose-700 transition">Mark lost</button>
            </div>
        </form>
    </div>
</div>
