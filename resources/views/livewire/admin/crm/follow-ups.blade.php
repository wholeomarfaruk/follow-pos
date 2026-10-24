<div x-data x-init="$store.pageName = { name: 'CRM · Follow-ups', slug: 'crm-followups' }">

    @include('livewire.admin.crm.partials.tabs')

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div class="flex flex-wrap gap-2">
            @foreach (['overdue' => 'Overdue', 'today' => 'Today', 'upcoming' => 'Next 7 days', 'done' => 'Done today'] as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium border transition
                        {{ $tab === $key ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm' : 'bg-white text-gray-600 border-gray-200 hover:border-indigo-300' }}">
                    {{ $label }}
                    <span class="inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full text-[11px] font-semibold
                        {{ $tab === $key ? 'bg-white/20 text-white' : ($key === 'overdue' && $counts[$key] ? 'bg-rose-100 text-rose-600' : 'bg-gray-100 text-gray-500') }}">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>
        @if ($canManage)
            <select wire:model.live="who" class="text-sm rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                <option value="mine">Mine + unassigned</option>
                <option value="all">Everyone's</option>
            </select>
        @endif
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 divide-y divide-gray-100">
        @forelse ($items as $f)
            @php $lead = $f->lead; @endphp
            <div class="px-5 py-4 flex flex-wrap items-start gap-4" wire:key="f-{{ $f->id }}">
                <div class="w-28 shrink-0">
                    <p class="text-sm font-medium {{ ! $f->done_at && $f->due_at->isPast() ? 'text-rose-600' : 'text-gray-800' }}">{{ local_time($f->due_at)->format('h:i A') }}</p>
                    <p class="text-xs text-gray-400">{{ local_time($f->due_at)->format('D d M') }}</p>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('admin.crm.leads.show', $lead) }}" class="text-sm font-semibold text-gray-800 hover:text-indigo-600">{{ $lead->name }}</a>
                        @if ($lead->stage)
                            <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-medium {{ $lead->stage->badgeClass() }}">{{ $lead->stage->name }}</span>
                        @endif
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium {{ $lead->priorityClass() }}">{{ \App\Models\CrmLead::PRIORITIES[$lead->priority] ?? '' }}</span>
                    </div>
                    <p class="text-sm text-gray-600 mt-0.5">{{ $f->body }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">{{ $lead->phone }}{{ $lead->interest ? ' · ' . \Illuminate\Support\Str::limit($lead->interest, 60) : '' }}{{ $canManage && $lead->assignee ? ' · ' . $lead->assignee->name : '' }}</p>

                    @if ($completingId === $f->id)
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <select wire:model="completeOutcome" class="text-sm rounded-lg border border-gray-300 px-2 py-1.5">
                                <option value="">Outcome…</option>
                                @foreach (\App\Models\CrmActivity::OUTCOMES as $k => $label)
                                    <option value="{{ $k }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <input wire:model="completeNote" type="text" placeholder="Call note (optional)" class="flex-1 min-w-[180px] text-sm rounded-lg border border-gray-300 px-3 py-1.5">
                            <button wire:click="complete" type="button" class="px-3 py-1.5 rounded-lg text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700">Save</button>
                            <button wire:click="$set('completingId', null)" type="button" class="text-sm text-gray-500">Cancel</button>
                        </div>
                    @endif
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    @if ($lead->phone)
                        <a href="tel:{{ $lead->phone }}" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-emerald-50 text-emerald-700 hover:bg-emerald-100">Call</a>
                        <a href="{{ $lead->whatsappUrl() }}" target="_blank" rel="noopener" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-green-50 text-green-700 hover:bg-green-100">WhatsApp</a>
                    @endif
                    @if (! $f->done_at)
                        <button wire:click="openComplete({{ $f->id }})" type="button" class="px-3 py-1.5 rounded-lg text-xs font-medium text-white bg-emerald-600 hover:bg-emerald-700">Done</button>
                        <div x-data="{ open: false }" class="relative">
                            <button @click="open = !open" @click.outside="open = false" type="button" class="px-2.5 py-1.5 rounded-lg text-xs font-medium bg-gray-100 text-gray-600 hover:bg-gray-200">Snooze ▾</button>
                            <div x-cloak x-show="open" class="absolute right-0 mt-1 w-32 bg-white border border-gray-200 rounded-lg shadow-lg z-10 py-1 text-sm">
                                <button wire:click="snooze({{ $f->id }}, '1h')" @click="open = false" type="button" class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">1 hour</button>
                                <button wire:click="snooze({{ $f->id }}, 'tomorrow')" @click="open = false" type="button" class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">Tomorrow 11am</button>
                                <button wire:click="snooze({{ $f->id }}, '3d')" @click="open = false" type="button" class="block w-full text-left px-3 py-1.5 hover:bg-gray-50">In 3 days</button>
                            </div>
                        </div>
                    @else
                        <span class="text-xs text-emerald-600">✓ {{ \App\Models\CrmActivity::OUTCOMES[$f->outcome] ?? 'Done' }}</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="px-5 py-16 text-center text-sm text-gray-400">
                {{ $tab === 'overdue' ? 'Nothing overdue. 🎉' : 'No follow-ups here.' }}
            </div>
        @endforelse
    </div>
    @if ($items->hasPages())
        <div class="mt-4">{{ $items->links() }}</div>
    @endif
</div>
