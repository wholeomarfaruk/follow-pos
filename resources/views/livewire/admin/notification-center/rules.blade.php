<div x-data x-init="$store.pageName = { name: 'Notification Center · Rules', slug: 'notification-rules' }">

    @include('livewire.admin.notification-center.partials.tabs')

    <div class="mb-4 rounded-xl border border-indigo-100 bg-indigo-50/60 px-4 py-3 text-sm text-indigo-900">
        Each row is one message: <b>an event</b> going to <b>one kind of recipient</b>. Switch it on, pick channels, edit the text.
        Customer / supplier SMS start <b>off</b> (they cost money); staff in-app alerts start on.
        Every send — or why it was skipped — is in the <a href="{{ route('admin.notification-center.outbox') }}" class="underline font-medium">Outbox</a>.
    </div>

    <div class="space-y-6">
        @foreach ($groups as $group => $events)
            <section id="group-{{ $group }}">
                <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2 px-1">{{ $group }}</h2>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200 divide-y divide-gray-100">
                    @foreach ($events as $key => $event)
                        <div class="px-5 py-4">
                            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
                                <div>
                                    <span class="text-sm font-semibold text-gray-900">{{ $event['label'] }}</span>
                                    <span class="ml-2 inline-flex px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-500">{{ $event['trigger'] === 'daily' ? 'Daily' : 'Instant' }} · {{ \App\NotificationHub\NotificationCatalog::CATEGORIES[$event['category']] }}</span>
                                    <p class="text-xs text-gray-500 mt-0.5">{{ $event['description'] }}</p>
                                </div>
                            </div>
                            <div class="space-y-2">
                                @foreach ($event['audiences'] as $audience => $def)
                                    @php $rule = ($rules[$key] ?? collect())->firstWhere('audience', $audience); @endphp
                                    @if ($rule)
                                        <div class="flex flex-wrap items-center gap-3 rounded-xl border px-3 py-2.5 {{ $rule->is_active ? 'border-indigo-200 bg-indigo-50/30' : 'border-gray-200' }}" wire:key="rule-{{ $rule->id }}">
                                            <button type="button" @if ($canManage) wire:click="toggle({{ $rule->id }})" @endif
                                                class="relative inline-flex h-5 w-9 shrink-0 rounded-full transition {{ $rule->is_active ? 'bg-indigo-600' : 'bg-gray-300' }} {{ $canManage ? 'cursor-pointer' : 'cursor-not-allowed opacity-60' }}"
                                                role="switch" aria-checked="{{ $rule->is_active ? 'true' : 'false' }}">
                                                <span class="inline-block h-4 w-4 mt-0.5 rounded-full bg-white shadow transition {{ $rule->is_active ? 'ml-[18px]' : 'ml-0.5' }}"></span>
                                            </button>
                                            <span class="text-sm font-medium text-gray-800 w-32">→ {{ \App\NotificationHub\NotificationCatalog::AUDIENCES[$audience] }}</span>
                                            <div class="flex flex-wrap gap-1.5">
                                                @foreach (\App\NotificationHub\NotificationCatalog::CHANNEL_LABELS as $ch => $chLabel)
                                                    @php
                                                        $on = in_array($ch, $rule->channelList(), true);
                                                        $usable = ! (in_array($ch, ['push', 'database'], true) && in_array($audience, ['lead'], true));
                                                    @endphp
                                                    @if ($usable)
                                                        <button type="button" @if ($canManage) wire:click="toggleChannel({{ $rule->id }}, '{{ $ch }}')" @endif
                                                            class="px-2 py-0.5 rounded-full text-[11px] font-medium border transition {{ $on ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-500 border-gray-300 hover:border-indigo-300' }}">
                                                            {{ $chLabel }}
                                                        </button>
                                                    @endif
                                                @endforeach
                                            </div>
                                            @if ($event['trigger'] === 'daily')
                                                <span class="text-xs text-gray-500">at {{ $rule->send_time }}</span>
                                            @endif
                                            <p class="flex-1 min-w-[160px] text-xs text-gray-500 truncate" title="{{ $rule->sms_body ?: $rule->push_body }}">{{ $rule->sms_body ?: $rule->push_body }}</p>
                                            <button wire:click="openEdit({{ $rule->id }})" type="button" class="px-3 py-1 rounded-lg text-xs font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100">{{ $canManage ? 'Edit' : 'View' }}</button>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>

    {{-- Edit modal --}}
    <div x-cloak x-data="{ open: @entangle('editModal') }" x-show="open" x-transition
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog">
        @if ($editing)
            <div class="w-full max-w-4xl bg-white rounded-2xl shadow-2xl overflow-hidden max-h-[92vh] flex flex-col" @click.outside="open = false">
                <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 shrink-0">
                    <div class="flex-1">
                        <h2 class="text-base font-semibold text-gray-900">{{ $event['label'] }} → {{ \App\NotificationHub\NotificationCatalog::AUDIENCES[$editing->audience] }}</h2>
                        <p class="text-xs text-gray-500">{{ $event['description'] }}</p>
                    </div>
                    <button @click="open = false" type="button" class="w-8 h-8 flex items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 transition">✕</button>
                </div>

                <div class="overflow-y-auto grid grid-cols-1 lg:grid-cols-5">
                    <form wire:submit.prevent="save" class="lg:col-span-3 px-6 py-5 space-y-5 border-r border-gray-100">
                        <div>
                            <p class="text-xs font-medium text-gray-600 mb-1.5">Placeholders you can use</p>
                            <div class="flex flex-wrap gap-1">
                                @foreach ($event['placeholders'] as $p)
                                    <code class="px-1.5 py-0.5 rounded bg-gray-100 text-[11px] text-gray-700 font-mono">{{ '{' . $p . '}' }}</code>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <p class="text-xs font-medium text-gray-600 mb-1.5">Channels</p>
                            <div class="flex flex-wrap gap-3">
                                @foreach (\App\NotificationHub\NotificationCatalog::CHANNEL_LABELS as $ch => $chLabel)
                                    @continue(in_array($ch, ['push', 'database'], true) && $editing->audience === 'lead')
                                    <label class="flex items-center gap-1.5 text-sm text-gray-700">
                                        <input type="checkbox" wire:model.live="edit.channels" value="{{ $ch }}" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                        {{ $chLabel }}
                                    </label>
                                @endforeach
                            </div>
                            @if (! in_array($editing->audience, \App\NotificationHub\NotificationCatalog::STAFF_AUDIENCES, true) && array_intersect(['push', 'database'], $edit['channels'] ?? []))
                                <p class="text-xs text-amber-700 mt-1">Web push / in-app only reach people who log in to this panel (their profile is linked to a user).</p>
                            @endif
                        </div>

                        <div x-data="{ get text() { return ($wire.edit && $wire.edit.sms_body) || '' },
                                get unicode() { return /[^\x00-\x7F]/.test(this.text || '') },
                                get parts() { const n = (this.text || '').length; if (!n) return 0; const one = this.unicode ? 70 : 160, multi = this.unicode ? 67 : 153; return n <= one ? 1 : Math.ceil(n / multi) } }">
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="text-xs font-medium text-gray-600">SMS text</label>
                                <span class="text-[11px] text-gray-400" x-text="(text || '').length + ' chars · ' + parts + ' SMS' + (unicode ? ' (Bangla/Unicode)' : '')"></span>
                            </div>
                            <textarea wire:model.live.debounce.400ms="edit.sms_body" rows="3" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"></textarea>
                            <p class="text-[11px] text-gray-400 mt-1">Also used for email / push when those are left empty. The {business} name, then numbers, are filled in on send.</p>
                        </div>

                        @if (in_array('email', $edit['channels'] ?? [], true))
                            <div class="space-y-2">
                                <label class="block text-xs font-medium text-gray-600">Email subject &amp; body</label>
                                <input wire:model.live.debounce.400ms="edit.email_subject" type="text" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                <textarea wire:model.live.debounce.400ms="edit.email_body" rows="5" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"></textarea>
                            </div>
                        @endif

                        @if (array_intersect(['push', 'database'], $edit['channels'] ?? []))
                            <div class="space-y-2">
                                <label class="block text-xs font-medium text-gray-600">Push / in-app title &amp; text</label>
                                <input wire:model.live.debounce.400ms="edit.push_title" type="text" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                                <input wire:model.live.debounce.400ms="edit.push_body" type="text" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                            </div>
                        @endif

                        @if ($event['trigger'] === 'daily' || ! empty($event['conditions']) || $editing->audience === 'staff')
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-4 border-t border-gray-100">
                                @if ($event['trigger'] === 'daily')
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Send at (every day)</label>
                                        <input wire:model="edit.send_time" type="time" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                        @error('edit.send_time') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                                    </div>
                                @endif
                                @if ($editing->event_key === 'promise.due')
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5">When</label>
                                        <select wire:model="edit.offset_days" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                            <option value="-1">1 day before the date</option>
                                            <option value="0">On the date</option>
                                            <option value="1">1 day after (missed)</option>
                                        </select>
                                    </div>
                                @endif
                                @foreach ($event['conditions'] ?? [] as $ckey => $cdef)
                                    <div class="{{ $cdef['type'] === 'sources' ? 'sm:col-span-2' : '' }}">
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5">{{ $cdef['label'] }}</label>
                                        @if ($cdef['type'] === 'sources')
                                            <div class="flex flex-wrap gap-3">
                                                @foreach ($orderSources as $sv => $sl)
                                                    <label class="flex items-center gap-1.5 text-sm text-gray-700">
                                                        <input type="checkbox" wire:model="edit.conditions.{{ $ckey }}" value="{{ $sv }}" @disabled(! $canManage) class="rounded border-gray-300 text-indigo-600">
                                                        {{ $sl }}
                                                    </label>
                                                @endforeach
                                            </div>
                                        @else
                                            <input wire:model="edit.conditions.{{ $ckey }}" type="number" min="0" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                        @endif
                                    </div>
                                @endforeach
                                @if ($editing->audience === 'staff')
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Which staff</label>
                                        <select wire:model="edit.staff_role" @disabled(! $canManage) class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                            <option value="">{{ str_starts_with($editing->event_key, 'lead.') ? 'Users with CRM manage access' : 'Admins (superadmin / admin)' }}</option>
                                            @foreach ($roles as $role)
                                                <option value="{{ $role }}">Role: {{ $role }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($canManage)
                            <div class="flex items-center justify-between gap-2 pt-4 border-t border-gray-100">
                                <button wire:click="resetToDefault" wire:confirm="Put back the default texts and timing?" type="button" class="text-xs text-gray-500 hover:text-gray-700">Reset to default</button>
                                <div class="flex gap-2">
                                    <button @click="open = false" type="button" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                                    <button type="submit" class="px-5 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">Save</button>
                                </div>
                            </div>
                        @endif
                    </form>

                    {{-- Preview + test --}}
                    <div class="lg:col-span-2 px-6 py-5 bg-gray-50/60 space-y-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Preview <span class="normal-case font-normal">(sample values)</span></p>
                        @if ($preview['sms'] ?? '')
                            <div>
                                <p class="text-[11px] text-gray-400 mb-1">SMS</p>
                                <div class="rounded-2xl rounded-tl-sm bg-white border border-gray-200 px-3 py-2 text-sm text-gray-800 whitespace-pre-line">{{ $preview['sms'] }}</div>
                            </div>
                        @endif
                        @if (in_array('email', $edit['channels'] ?? [], true))
                            <div>
                                <p class="text-[11px] text-gray-400 mb-1">Email</p>
                                <div class="rounded-xl bg-white border border-gray-200 px-3 py-2 text-sm">
                                    <p class="font-semibold text-gray-800">{{ $preview['email_subject'] }}</p>
                                    <p class="text-gray-600 mt-1 whitespace-pre-line">{{ $preview['email_body'] }}</p>
                                </div>
                            </div>
                        @endif
                        @if (array_intersect(['push', 'database'], $edit['channels'] ?? []))
                            <div>
                                <p class="text-[11px] text-gray-400 mb-1">Push / in-app</p>
                                <div class="rounded-xl bg-white border border-gray-200 px-3 py-2 text-sm shadow-sm">
                                    <p class="font-semibold text-gray-800">{{ $preview['push_title'] }}</p>
                                    <p class="text-gray-600">{{ $preview['push_body'] }}</p>
                                </div>
                            </div>
                        @endif

                        @if ($canManage)
                            <div class="pt-4 border-t border-gray-200 space-y-2">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Send a test</p>
                                <select wire:model.live="testChannel" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white">
                                    @foreach (\App\NotificationHub\NotificationCatalog::CHANNEL_LABELS as $ch => $chLabel)
                                        <option value="{{ $ch }}">{{ $chLabel }}{{ in_array($ch, ['push', 'database'], true) ? ' (to me)' : '' }}</option>
                                    @endforeach
                                </select>
                                @if (in_array($testChannel, ['sms', 'email'], true))
                                    <input wire:model="testTo" type="text" placeholder="{{ $testChannel === 'sms' ? '01XXXXXXXXX' : 'you@example.com' }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white">
                                    @error('testTo') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                                @endif
                                <button wire:click="sendTest" wire:loading.attr="disabled" type="button" class="w-full px-4 py-2 text-sm font-medium text-indigo-700 bg-white border border-indigo-200 rounded-lg hover:bg-indigo-50 disabled:opacity-60">
                                    <span wire:loading.remove wire:target="sendTest">Send test</span>
                                    <span wire:loading wire:target="sendTest">Sending…</span>
                                </button>
                                @if ($testResult)
                                    @php [$kind, $msg] = explode(':', $testResult, 2); @endphp
                                    <p class="text-xs {{ $kind === 'ok' ? 'text-emerald-600' : 'text-rose-600' }}">{{ $msg }}</p>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
