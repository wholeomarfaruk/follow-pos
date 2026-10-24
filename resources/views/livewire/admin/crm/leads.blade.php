<div x-data x-init="$store.pageName = { name: 'CRM · Leads', slug: 'crm-leads' }">

    @include('livewire.admin.crm.partials.tabs')

    {{-- Toolbar --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 mb-4">
        <div class="flex flex-wrap items-center gap-3 px-5 py-4">
            <div class="relative flex-1 min-w-[200px]">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Name, phone, code, company…"
                    class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-gray-300 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
            </div>

            @if ($view !== 'board')
                <select wire:model.live="status" class="text-sm rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    <option value="open">Open</option>
                    <option value="won">Won</option>
                    <option value="lost">Lost</option>
                    <option value="converted">Converted</option>
                    <option value="all">All</option>
                </select>
                <select wire:model.live="stage" class="text-sm rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    <option value="">All stages</option>
                    @foreach ($stages as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="source" class="text-sm rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                <option value="">All sources</option>
                @foreach (\App\Models\CrmLead::SOURCES as $k => $label)
                    <option value="{{ $k }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="priority" class="text-sm rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                <option value="">Any priority</option>
                @foreach (\App\Models\CrmLead::PRIORITIES as $k => $label)
                    <option value="{{ $k }}">{{ $label }}</option>
                @endforeach
            </select>
            @if ($canManage)
                <select wire:model.live="assignee" class="text-sm rounded-lg border border-gray-300 px-3 py-2 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    <option value="">Everyone</option>
                    <option value="me">Assigned to me</option>
                    <option value="none">Unassigned</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            @endif

            <div class="flex rounded-lg border border-gray-300 overflow-hidden text-sm">
                <button type="button" wire:click="$set('view', 'list')" class="px-3 py-2 {{ $view !== 'board' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50' }}">List</button>
                <button type="button" wire:click="$set('view', 'board')" class="px-3 py-2 border-l border-gray-300 {{ $view === 'board' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50' }}">Board</button>
            </div>

            <button wire:click="openCreate" type="button"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition shadow-sm">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add lead
            </button>
        </div>

        @if ($canManage && count($selected) && $view !== 'board')
            <div class="flex flex-wrap items-center gap-3 px-5 py-3 border-t border-gray-100 bg-indigo-50/50">
                <span class="text-sm font-medium text-indigo-700">{{ count($selected) }} selected</span>
                <select wire:model="bulkStage" class="text-sm rounded-lg border border-gray-300 px-3 py-1.5">
                    <option value="">Move to stage…</option>
                    @foreach ($stages->where('is_lost', false) as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
                <select wire:model="bulkAssignee" class="text-sm rounded-lg border border-gray-300 px-3 py-1.5">
                    <option value="">Assign to…</option>
                    <option value="none">Nobody</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
                <button wire:click="bulkApply" type="button" class="px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">Apply</button>
                <button wire:click="bulkDelete" wire:confirm="Delete the selected leads?" type="button" class="px-3 py-1.5 text-sm font-medium text-rose-600 bg-white border border-rose-200 rounded-lg hover:bg-rose-50">Delete</button>
                <button wire:click="$set('selected', [])" type="button" class="text-sm text-gray-500 hover:text-gray-700">Clear</button>
            </div>
        @endif
    </div>

    @if ($view === 'board')
        {{-- Kanban: drag a card to another column to change its stage. --}}
        <div class="flex gap-4 overflow-x-auto pb-4 items-start"
            x-data
            x-init="$nextTick(() => {
                $el.querySelectorAll('[data-stage-col]').forEach(col => {
                    if (col._sortable || ! window.Sortable) return;
                    col._sortable = window.Sortable.create(col, {
                        group: 'crm-leads', animation: 150, draggable: '[data-lead]', ghostClass: 'opacity-40',
                        onEnd: (e) => {
                            if (e.from === e.to) return;
                            const lead = +e.item.dataset.lead, stage = +e.to.dataset.stageCol;
                            // Put the card back; Livewire re-renders it in its new column.
                            e.from.insertBefore(e.item, e.from.children[e.oldIndex] || null);
                            $wire.moveLead(lead, stage);
                        },
                    });
                });
            })">
            @foreach ($stages as $s)
                @php $c = $counts[$s->id] ?? null; @endphp
                <div class="w-72 shrink-0 bg-gray-100/70 rounded-2xl border border-gray-200 flex flex-col max-h-[calc(100vh-260px)]" wire:key="col-{{ $s->id }}">
                    <div class="px-4 py-3 flex items-center justify-between gap-2 border-b border-gray-200">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="h-2.5 w-2.5 rounded-full shrink-0 {{ $s->dotClass() }}"></span>
                            <span class="text-sm font-semibold text-gray-800 truncate">{{ $s->name }}</span>
                            <span class="text-xs text-gray-400">{{ (int) ($c->n ?? 0) }}</span>
                        </div>
                        @if (($c->v ?? 0) > 0)
                            <span class="text-[11px] text-gray-500">৳{{ number_format((float) $c->v) }}</span>
                        @endif
                    </div>
                    <div class="p-2 space-y-2 overflow-y-auto flex-1 min-h-[80px]" data-stage-col="{{ $s->id }}">
                        @foreach ($board[$s->id] ?? [] as $lead)
                            <div data-lead="{{ $lead->id }}" wire:key="card-{{ $lead->id }}"
                                class="bg-white rounded-xl border border-gray-200 p-3 shadow-sm cursor-grab active:cursor-grabbing hover:border-indigo-300 transition">
                                <div class="flex items-start justify-between gap-2">
                                    <a href="{{ route('admin.crm.leads.show', $lead) }}" class="text-sm font-medium text-gray-800 hover:text-indigo-600 leading-snug">{{ $lead->name }}</a>
                                    <span class="shrink-0 inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium {{ $lead->priorityClass() }}">{{ \App\Models\CrmLead::PRIORITIES[$lead->priority] ?? $lead->priority }}</span>
                                </div>
                                @if ($lead->company)
                                    <p class="text-xs text-gray-500 mt-0.5 truncate">{{ $lead->company }}</p>
                                @endif
                                <div class="flex items-center gap-2 mt-2 text-xs text-gray-500">
                                    @if ($lead->phone)<span>{{ $lead->phone }}</span>@endif
                                    <span class="text-gray-300">·</span>
                                    <span>{{ $lead->sourceLabel() }}</span>
                                </div>
                                @if ($lead->interest)
                                    <p class="text-xs text-gray-500 mt-1 line-clamp-2">{{ $lead->interest }}</p>
                                @endif
                                <div class="flex items-center justify-between mt-2 pt-2 border-t border-gray-100">
                                    @if ($lead->next_follow_up_at)
                                        <span class="text-[11px] {{ $lead->next_follow_up_at->isPast() ? 'text-rose-600 font-medium' : 'text-gray-500' }}">
                                            ⏰ {{ local_time($lead->next_follow_up_at)->format('d M, h:i A') }}
                                        </span>
                                    @else
                                        <span class="text-[11px] text-gray-300">No follow-up</span>
                                    @endif
                                    <span class="text-[11px] text-gray-500 truncate max-w-[90px]" title="{{ $lead->assignee?->name }}">{{ $lead->assignee?->name ?? 'Unassigned' }}</span>
                                </div>
                                @if ($lead->converted_at)
                                    <span class="mt-2 inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-emerald-50 text-emerald-700">Customer</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/40">
                            @if ($canManage)
                                <th class="pl-5 py-3 w-8"></th>
                            @endif
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Lead</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Stage</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Source</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Assigned</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Next follow-up</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Value</th>
                            <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($leads as $lead)
                            <tr class="hover:bg-gray-50/50 transition" wire:key="row-{{ $lead->id }}">
                                @if ($canManage)
                                    <td class="pl-5 py-3"><input type="checkbox" wire:model.live="selected" value="{{ $lead->id }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"></td>
                                @endif
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="h-9 w-9 shrink-0 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center text-xs font-semibold">{{ $lead->initials() }}</span>
                                        <div class="min-w-0">
                                            <a href="{{ route('admin.crm.leads.show', $lead) }}" class="text-sm font-medium text-gray-800 hover:text-indigo-600">{{ $lead->name }}</a>
                                            <span class="ml-1 inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium {{ $lead->priorityClass() }}">{{ \App\Models\CrmLead::PRIORITIES[$lead->priority] ?? $lead->priority }}</span>
                                            <p class="text-xs text-gray-400">{{ $lead->code }} · {{ $lead->phone ?: $lead->email }}{{ $lead->company ? ' · ' . $lead->company : '' }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    @if ($lead->stage)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $lead->stage->badgeClass() }}">{{ $lead->stage->name }}</span>
                                    @endif
                                    @if ($lead->converted_at)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">Customer</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-gray-600">{{ $lead->sourceLabel() }}</td>
                                <td class="px-5 py-3 text-sm text-gray-600">{{ $lead->assignee?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-sm">
                                    @if ($lead->next_follow_up_at)
                                        <span class="{{ $lead->next_follow_up_at->isPast() ? 'text-rose-600 font-medium' : 'text-gray-600' }}">{{ local_time($lead->next_follow_up_at)->format('d M, h:i A') }}</span>
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-sm text-right text-gray-700">{{ (float) $lead->expected_value ? '৳' . number_format((float) $lead->expected_value) : '—' }}</td>
                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($lead->phone)
                                            <a href="tel:{{ $lead->phone }}" title="Call" class="p-1.5 rounded-lg text-gray-400 hover:text-emerald-600 hover:bg-emerald-50">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z"/></svg>
                                            </a>
                                            <a href="{{ $lead->whatsappUrl() }}" target="_blank" rel="noopener" title="WhatsApp" class="p-1.5 rounded-lg text-gray-400 hover:text-green-600 hover:bg-green-50">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z"/></svg>
                                            </a>
                                        @endif
                                        <button wire:click="openEdit({{ $lead->id }})" type="button" title="Edit" class="p-1.5 rounded-lg text-gray-400 hover:text-indigo-600 hover:bg-indigo-50">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
                                        </button>
                                        @if ($canManage)
                                            <button wire:click="delete({{ $lead->id }})" wire:confirm="Delete this lead?" type="button" title="Delete" class="p-1.5 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50">
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-5 py-16 text-center">
                                    <p class="text-sm text-gray-500">No leads here yet.</p>
                                    <button wire:click="openCreate" type="button" class="mt-2 text-sm font-medium text-indigo-600 hover:text-indigo-800">Add the first lead</button>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($leads->hasPages())
                <div class="px-5 py-3 border-t border-gray-100">{{ $leads->links() }}</div>
            @endif
        </div>
    @endif

    @include('livewire.admin.crm.partials.lead-form-modal')
    @include('livewire.admin.crm.partials.lost-modal')
</div>
