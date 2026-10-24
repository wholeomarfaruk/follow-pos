<div x-data x-init="$store.pageName = { name: 'CRM · {{ addslashes($lead->name) }}', slug: 'crm-lead' }">

    @include('livewire.admin.crm.partials.tabs')

    {{-- Header --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 mb-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-4 min-w-0">
                <span class="h-12 w-12 shrink-0 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center text-base font-semibold">{{ $lead->initials() }}</span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-lg font-semibold text-gray-900">{{ $lead->name }}</h1>
                        <span class="text-xs text-gray-400">{{ $lead->code }}</span>
                        <span class="inline-flex px-2 py-0.5 rounded text-xs font-medium {{ $lead->priorityClass() }}">{{ \App\Models\CrmLead::PRIORITIES[$lead->priority] ?? $lead->priority }}</span>
                        @if ($lead->converted_at)
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">Customer since {{ local_time($lead->converted_at)->format('d M Y') }}</span>
                        @endif
                    </div>
                    <p class="text-sm text-gray-500 mt-0.5">
                        {{ $lead->company ? $lead->company . ' · ' : '' }}{{ $lead->sourceLabel() }} · added {{ local_time($lead->created_at)->format('d M Y') }}{{ $lead->creator ? ' by ' . $lead->creator->name : '' }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2 mt-3">
                        @if ($lead->phone)
                            <a href="tel:{{ $lead->phone }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-emerald-50 text-emerald-700 hover:bg-emerald-100">📞 {{ $lead->phone }}</a>
                            <a href="{{ $lead->whatsappUrl() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-green-50 text-green-700 hover:bg-green-100">WhatsApp</a>
                        @endif
                        @if ($lead->email)
                            <a href="mailto:{{ $lead->email }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-sky-50 text-sky-700 hover:bg-sky-100">✉ {{ $lead->email }}</a>
                        @endif
                        <button wire:click="openEdit({{ $lead->id }})" type="button" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-gray-100 text-gray-700 hover:bg-gray-200">Edit details</button>
                    </div>
                </div>
            </div>

            <div class="flex flex-col items-end gap-2">
                @if ($lead->converted_at && $lead->profile)
                    @if (Route::has('accounts.profiles.show'))
                        <a href="{{ route('accounts.profiles.show', $lead->profile) }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-emerald-700 bg-emerald-50 rounded-lg hover:bg-emerald-100">
                            Open profile {{ $lead->profile->code }} →
                        </a>
                    @endif
                    @if ($lead->customer)
                        <span class="text-xs text-gray-500">Customer {{ $lead->customer->customer_code }}</span>
                    @endif
                @else
                    <button wire:click="convert" wire:confirm="Convert {{ $lead->name }} to a customer? A customer profile will be created (or reused when the phone already has one)."
                        type="button" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        Convert to customer
                    </button>
                    @if (! $lead->stage?->is_lost)
                        <button wire:click="askLost({{ $lead->id }})" type="button" class="text-xs font-medium text-rose-600 hover:text-rose-800">Mark lost</button>
                    @endif
                @endif
            </div>
        </div>

        {{-- Stage stepper --}}
        <div class="mt-5 flex flex-wrap gap-1.5">
            @foreach ($stages as $s)
                @php $current = $lead->stage_id === $s->id; @endphp
                <button type="button" wire:click="changeStage({{ $s->id }})" @disabled($current)
                    class="flex-1 min-w-[110px] px-3 py-2 rounded-lg text-xs font-medium border transition
                        {{ $current ? $s->badgeClass() . ' border-transparent ring-2 ring-offset-1 ring-indigo-300' : 'bg-white text-gray-500 border-gray-200 hover:border-indigo-300 hover:text-gray-700' }}">
                    {{ $s->name }}
                    @if ($s->probability && ! $s->isClosed())<span class="opacity-60">· {{ $s->probability }}%</span>@endif
                </button>
            @endforeach
        </div>
        @if ($lead->stage?->is_lost && $lead->lost_reason)
            <p class="mt-2 text-xs text-rose-600">Lost: {{ $lead->lost_reason }}</p>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Left: details --}}
        <div class="space-y-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Details</h3>
                <dl class="space-y-2.5 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Assigned to</dt>
                        <dd>
                            @if ($canManage)
                                <select wire:change="reassign($event.target.value)" class="text-sm rounded-lg border border-gray-300 px-2 py-1 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                    <option value="">Nobody</option>
                                    @foreach ($users as $u)
                                        <option value="{{ $u->id }}" @selected($lead->assigned_to === $u->id)>{{ $u->name }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span class="text-gray-800">{{ $lead->assignee?->name ?? '—' }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Expected value</dt><dd class="text-gray-800">{{ (float) $lead->expected_value ? '৳' . number_format((float) $lead->expected_value) : '—' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Last contacted</dt><dd class="text-gray-800">{{ $lead->last_contacted_at ? local_time($lead->last_contacted_at)->diffForHumans() : 'Never' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Next follow-up</dt><dd class="{{ $lead->next_follow_up_at?->isPast() ? 'text-rose-600 font-medium' : 'text-gray-800' }}">{{ $lead->next_follow_up_at ? local_time($lead->next_follow_up_at)->format('d M, h:i A') : '—' }}</dd></div>
                    @if ($lead->interest)
                        <div><dt class="text-gray-500">Interested in</dt><dd class="text-gray-800 mt-0.5">{{ $lead->interest }}</dd></div>
                    @endif
                    @if ($lead->address)
                        <div><dt class="text-gray-500">Address</dt><dd class="text-gray-800 mt-0.5">{{ $lead->address }}</dd></div>
                    @endif
                    @if ($lead->tags)
                        <div class="flex flex-wrap gap-1 pt-1">
                            @foreach ($lead->tags as $tag)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">#{{ $tag }}</span>
                            @endforeach
                        </div>
                    @endif
                    @if ($lead->notes)
                        <div class="pt-2 border-t border-gray-100"><dt class="text-gray-500">Notes</dt><dd class="text-gray-700 mt-0.5 whitespace-pre-line">{{ $lead->notes }}</dd></div>
                    @endif
                </dl>
                <label class="mt-4 pt-3 border-t border-gray-100 flex items-center gap-2 text-xs text-gray-600">
                    <input type="checkbox" wire:click="toggleOptOut" @checked($optedOut) class="rounded border-gray-300 text-rose-600 focus:ring-rose-500">
                    Don't send offers / campaigns to this lead
                </label>
            </div>

            @if ($duplicates['leads']->isNotEmpty() || ($duplicates['profile'] && ! $lead->master_profile_id))
                <div class="bg-amber-50 rounded-2xl border border-amber-200 p-5 text-sm text-amber-800">
                    <h3 class="font-semibold mb-2">Same phone elsewhere</h3>
                    @foreach ($duplicates['leads'] as $d)
                        <p><a href="{{ route('admin.crm.leads.show', $d) }}" class="underline">{{ $d->code }} · {{ $d->name }}</a> — {{ $d->converted_at ? 'customer' : ($d->stage?->name ?? '—') }}</p>
                    @endforeach
                    @if ($duplicates['profile'] && ! $lead->master_profile_id)
                        <p>Profile {{ $duplicates['profile']->code }} · {{ $duplicates['profile']->display_name }} — converting will reuse it.</p>
                    @endif
                </div>
            @endif

            {{-- Open follow-ups --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">Follow-ups</h3>
                <div class="space-y-2 mb-4">
                    @forelse ($openFollowUps as $f)
                        <div class="rounded-xl border {{ $f->due_at->isPast() ? 'border-rose-200 bg-rose-50/50' : 'border-gray-200' }} p-3" wire:key="fu-{{ $f->id }}">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-sm text-gray-800">{{ $f->body }}</p>
                                    <p class="text-xs mt-0.5 {{ $f->due_at->isPast() ? 'text-rose-600 font-medium' : 'text-gray-500' }}">{{ local_time($f->due_at)->format('D d M, h:i A') }} · {{ local_time($f->due_at)->diffForHumans() }}</p>
                                </div>
                                <button wire:click="openComplete({{ $f->id }})" type="button" class="shrink-0 px-2.5 py-1 rounded-lg text-xs font-medium text-white bg-emerald-600 hover:bg-emerald-700">Done</button>
                            </div>
                            @if ($completingId === $f->id)
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <select wire:model="completeOutcome" class="text-xs rounded-lg border border-gray-300 px-2 py-1">
                                        <option value="">Outcome…</option>
                                        @foreach (\App\Models\CrmActivity::OUTCOMES as $k => $label)
                                            <option value="{{ $k }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button wire:click="completeFollowUp" type="button" class="px-2.5 py-1 rounded-lg text-xs font-medium text-white bg-indigo-600">Save</button>
                                    <button wire:click="$set('completingId', null)" type="button" class="text-xs text-gray-500">Cancel</button>
                                </div>
                            @else
                                <div class="mt-2 flex gap-3 text-[11px] text-gray-500">
                                    <span>Snooze:</span>
                                    <button wire:click="snooze({{ $f->id }}, '1h')" type="button" class="hover:text-indigo-600">1 hour</button>
                                    <button wire:click="snooze({{ $f->id }}, 'tomorrow')" type="button" class="hover:text-indigo-600">Tomorrow</button>
                                    <button wire:click="snooze({{ $f->id }}, '3d')" type="button" class="hover:text-indigo-600">3 days</button>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">Nothing scheduled.</p>
                    @endforelse
                </div>
                <form wire:submit.prevent="scheduleFollowUp" class="space-y-2 pt-3 border-t border-gray-100">
                    <input wire:model="followUpAt" type="datetime-local" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    @error('followUpAt') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    <input wire:model="followUpNote" type="text" placeholder="What to do (e.g. send price list)" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    <button type="submit" class="w-full px-4 py-2 text-sm font-medium text-indigo-700 bg-indigo-50 rounded-lg hover:bg-indigo-100">Schedule follow-up</button>
                </form>
            </div>
        </div>

        {{-- Right: log + timeline --}}
        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <div class="flex flex-wrap gap-1.5 mb-3">
                    @foreach (\App\Models\CrmActivity::LOGGABLE as $type)
                        <button type="button" wire:click="$set('activityType', '{{ $type }}')"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium transition {{ $activityType === $type ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                            {{ \App\Models\CrmActivity::TYPES[$type] }}
                        </button>
                    @endforeach
                </div>
                <form wire:submit.prevent="logActivity" class="space-y-3">
                    @if ($activityType === 'email' && $sendMessage)
                        <input wire:model="emailSubject" type="text" placeholder="Subject" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                    @endif
                    <textarea wire:model="activityBody" rows="3"
                        placeholder="{{ match ($activityType) { 'call' => 'What was discussed? (optional)', 'sms', 'email' => $sendMessage ? 'Message — {name} and {business} are filled in' : 'What was sent', 'meeting' => 'Where / what was agreed', default => 'Write a note…' } }}"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"></textarea>
                    @error('activityBody') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-3">
                            @if ($activityType === 'call')
                                <select wire:model="activityOutcome" class="text-sm rounded-lg border border-gray-300 px-3 py-1.5">
                                    <option value="">Outcome…</option>
                                    @foreach (\App\Models\CrmActivity::OUTCOMES as $k => $label)
                                        <option value="{{ $k }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @endif
                            @if (in_array($activityType, ['sms', 'email'], true) && ($activityType === 'sms' ? $lead->phone : $lead->email))
                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" wire:model.live="sendMessage" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    Send it now to {{ $activityType === 'sms' ? $lead->phone : $lead->email }}
                                </label>
                            @endif
                        </div>
                        <button type="submit" wire:loading.attr="disabled" class="px-5 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-60">
                            {{ $sendMessage && in_array($activityType, ['sms', 'email'], true) ? 'Send & log' : 'Log ' . strtolower(\App\Models\CrmActivity::TYPES[$activityType]) }}
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-900 mb-4">Timeline</h3>
                <ol class="relative border-l border-gray-200 ml-2 space-y-5">
                    @forelse ($timeline as $a)
                        @php
                            $dot = match ($a->type) {
                                'call' => 'bg-emerald-500', 'sms', 'whatsapp' => 'bg-green-500', 'email' => 'bg-sky-500',
                                'meeting' => 'bg-violet-500', 'stage' => 'bg-indigo-500', 'follow_up' => 'bg-amber-500',
                                'system' => 'bg-gray-300', default => 'bg-gray-400',
                            };
                        @endphp
                        <li class="ml-5" wire:key="tl-{{ $a->id }}">
                            <span class="absolute -left-[5px] mt-1.5 h-2.5 w-2.5 rounded-full {{ $dot }}"></span>
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-sm">
                                    <span class="font-medium text-gray-800">{{ $a->typeLabel() }}</span>
                                    @if ($a->outcome)
                                        <span class="ml-1 inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-600">{{ \App\Models\CrmActivity::OUTCOMES[$a->outcome] ?? $a->outcome }}</span>
                                    @endif
                                    @if ($a->type === 'follow_up')
                                        <span class="ml-1 text-xs {{ $a->done_at ? 'text-emerald-600' : 'text-amber-600' }}">{{ $a->done_at ? 'done' : 'due ' . local_time($a->due_at)->format('d M, h:i A') }}</span>
                                    @endif
                                </p>
                                <span class="text-xs text-gray-400">{{ local_time($a->created_at)->format('d M Y, h:i A') }}{{ $a->user ? ' · ' . $a->user->name : '' }}</span>
                            </div>
                            @if ($a->body)
                                <p class="text-sm text-gray-600 mt-0.5 whitespace-pre-line">{{ $a->body }}</p>
                            @endif
                            @if (in_array($a->type, \App\Models\CrmActivity::LOGGABLE, true) && ($canManage || $a->user_id === auth()->id()))
                                <button wire:click="deleteActivity({{ $a->id }})" wire:confirm="Delete this entry?" type="button" class="text-[11px] text-gray-400 hover:text-rose-600 mt-1">Delete</button>
                            @endif
                        </li>
                    @empty
                        <li class="ml-5 text-sm text-gray-400">No activity yet.</li>
                    @endforelse
                </ol>
            </div>
        </div>
    </div>

    @include('livewire.admin.crm.partials.lead-form-modal')
    @include('livewire.admin.crm.partials.lost-modal')
</div>
