{{-- Add / edit lead. Needs: $form, $dupes, $editingId, $stages, $users, $canManage, and formModal / save() on the component. --}}
<div x-cloak x-data="{ open: @entangle('formModal') }" x-show="open" x-transition
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog">
    <div class="w-full max-w-2xl bg-white rounded-2xl shadow-2xl overflow-hidden max-h-[92vh] flex flex-col" @click.outside="open = false">
        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 shrink-0">
            <div class="w-8 h-8 rounded-lg bg-indigo-100 flex items-center justify-center shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/></svg>
            </div>
            <div class="flex-1"><h2 class="text-base font-semibold text-gray-900">{{ $editingId ? 'Edit lead' : 'New lead' }}</h2></div>
            <button @click="open = false" type="button" class="w-8 h-8 flex items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 transition">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form wire:submit.prevent="save" class="overflow-y-auto px-6 py-5 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Name <span class="text-red-500">*</span></label>
                    <input wire:model="form.name" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    @error('form.name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Phone</label>
                    <input wire:model.live.debounce.500ms="form.phone" type="text" inputmode="tel" placeholder="01XXXXXXXXX" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    @error('form.phone') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            @if (! empty($dupes['leads']) || ! empty($dupes['profile']))
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 space-y-1">
                    <p class="font-medium">This phone number is already known</p>
                    @foreach ($dupes['leads'] as $d)
                        <p>Lead <a href="{{ route('admin.crm.leads.show', $d['id']) }}" target="_blank" class="underline font-medium">{{ $d['code'] }} · {{ $d['name'] }}</a> ({{ $d['converted'] ? 'customer' : ($d['stage'] ?? '—') }})</p>
                    @endforeach
                    @if ($dupes['profile'])
                        <p>Profile <span class="font-medium">{{ $dupes['profile']['code'] }} · {{ $dupes['profile']['name'] }}</span> — converting this lead will reuse it.</p>
                    @endif
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Email</label>
                    <input wire:model="form.email" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    @error('form.email') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Company / shop</label>
                    <input wire:model="form.company" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Source</label>
                    <select wire:model="form.source" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        @foreach (\App\Models\CrmLead::SOURCES as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Priority</label>
                    <select wire:model="form.priority" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        @foreach (\App\Models\CrmLead::PRIORITIES as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Stage</label>
                    <select wire:model="form.stage_id" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        @foreach ($stages->where('is_lost', false) as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Assigned to</label>
                    <select wire:model="form.assigned_to" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-gray-50">
                        <option value="">{{ $editingId ? 'Nobody' : 'Auto (CRM settings)' }}</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Expected value (৳)</label>
                    <input wire:model="form.expected_value" type="number" min="0" step="1" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    @error('form.expected_value') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Tags <span class="text-gray-400">(comma separated)</span></label>
                    <input wire:model="form.tags" type="text" placeholder="wholesale, eid" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Interested in</label>
                <input wire:model="form.interest" type="text" placeholder="Products, quantity, budget…" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Address</label>
                <input wire:model="form.address" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1.5">Notes</label>
                <textarea wire:model="form.notes" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100">
                <button @click="open = false" type="button" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button type="submit" wire:loading.attr="disabled" class="px-5 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition disabled:opacity-60">{{ $editingId ? 'Save changes' : 'Add lead' }}</button>
            </div>
        </form>
    </div>
</div>
