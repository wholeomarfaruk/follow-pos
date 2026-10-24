<div x-data x-init="$store.pageName = { name: 'CRM · Settings', slug: 'crm-settings' }">

    @include('livewire.admin.crm.partials.tabs')

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        {{-- Stages --}}
        <div class="xl:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-gray-900">Pipeline stages</h2>
                    <p class="text-xs text-gray-400">Drag to reorder. Probability weights the pipeline value on the dashboard.</p>
                </div>
                <button wire:click="addStage" type="button" class="px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 rounded-lg hover:bg-indigo-100">+ Stage</button>
            </div>
            <div class="p-5">
                @error('stages') <p class="mb-3 text-sm text-rose-600">{{ $message }}</p> @enderror
                <div class="space-y-2"
                    x-data
                    x-init="$nextTick(() => { if (! window.Sortable || $el._sortable) return; $el._sortable = window.Sortable.create($el, { handle: '[data-handle]', animation: 150, onEnd: () => { const order = [...$el.children].map(c => c.dataset.index); $wire.reorder(order); } }); })">
                    @foreach ($stages as $i => $s)
                        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 p-3 bg-white" data-index="{{ $i }}" wire:key="stage-{{ $s['id'] ?? 'new-' . $i }}">
                            <span data-handle class="cursor-grab text-gray-300 hover:text-gray-500 select-none">⋮⋮</span>
                            <input wire:model="stages.{{ $i }}.name" type="text" placeholder="Stage name" class="flex-1 min-w-[140px] rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                            <select wire:model.live="stages.{{ $i }}.color" class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                @foreach ($colors as $c)
                                    <option value="{{ $c }}">{{ ucfirst($c) }}</option>
                                @endforeach
                            </select>
                            <span class="h-4 w-4 rounded-full {{ (\App\Models\CrmStage::COLORS[$s['color']] ?? \App\Models\CrmStage::COLORS['slate'])[1] }}"></span>
                            <div class="flex items-center gap-1">
                                <input wire:model="stages.{{ $i }}.probability" type="number" min="0" max="100" class="w-16 rounded-lg border border-gray-300 px-2 py-1.5 text-sm">
                                <span class="text-xs text-gray-400">%</span>
                            </div>
                            <label class="flex items-center gap-1 text-xs text-gray-600"><input type="checkbox" wire:model="stages.{{ $i }}.is_won" class="rounded border-gray-300 text-emerald-600"> Won</label>
                            <label class="flex items-center gap-1 text-xs text-gray-600"><input type="checkbox" wire:model="stages.{{ $i }}.is_lost" class="rounded border-gray-300 text-rose-600"> Lost</label>
                            <span class="text-xs text-gray-400 w-16 text-right">{{ $s['leads'] }} leads</span>
                            <button wire:click="removeStage({{ $i }})" wire:confirm="Remove this stage?" type="button" class="text-gray-300 hover:text-rose-600" title="Remove">✕</button>
                            @error("stages.$i.name") <p class="w-full text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-end mt-4">
                    <button wire:click="saveStages" type="button" class="px-5 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">Save stages</button>
                </div>
            </div>
        </div>

        {{-- Assignment --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">New leads</h2>
                <p class="text-xs text-gray-400">Who gets them, and the first follow-up.</p>
            </div>
            <form wire:submit.prevent="saveAssignment" class="p-5 space-y-4">
                <div class="space-y-2">
                    @foreach (['none' => ['Leave unassigned', 'Whoever adds the lead picks someone.'], 'fixed' => ['Always the same person', 'Every new lead goes to one user.'], 'round_robin' => ['Round-robin', 'Rotate between everyone with CRM access.']] as $val => [$label, $hint])
                        <label class="flex items-start gap-3 rounded-xl border p-3 cursor-pointer {{ $autoAssign === $val ? 'border-indigo-300 bg-indigo-50/50' : 'border-gray-200' }}">
                            <input type="radio" wire:model.live="autoAssign" value="{{ $val }}" class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                            <span><span class="block text-sm font-medium text-gray-800">{{ $label }}</span><span class="block text-xs text-gray-500">{{ $hint }}</span></span>
                        </label>
                    @endforeach
                </div>
                @if ($autoAssign === 'fixed')
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Assign to</label>
                        <select wire:model="assignUserId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <option value="">Choose…</option>
                            @foreach ($users as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                        @error('assignUserId') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1.5">First follow-up after (hours)</label>
                    <input wire:model="firstFollowUpHours" type="number" min="0" max="720" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <p class="text-xs text-gray-400 mt-1">0 = don't create one automatically.</p>
                    @error('firstFollowUpHours') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" wire:model="convertMovesToWon" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    Converting a lead moves it to the won stage
                </label>
                <div class="pt-2 border-t border-gray-100 text-xs text-gray-500">
                    Messages to leads and staff (new lead, assigned, follow-ups due, welcome) are set up in
                    <a href="{{ route('admin.notification-center.rules') }}#group-CRM" class="text-indigo-600 hover:text-indigo-800">Notification Center › Rules</a>.
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="px-5 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
