<div x-data x-init="$store.pageName = { name: 'Notification Center · Campaigns', slug: 'notification-campaigns' }" @if ($hasActive) wire:poll.10s @endif>

    @include('livewire.admin.notification-center.partials.tabs')

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <p class="text-sm text-gray-500 max-w-2xl">Offers, Eid greetings, new-arrival news, payment drives… Customers who opted out of marketing are skipped automatically, and SMS waits for the morning during quiet hours.</p>
        @if ($canSend)
            <div class="flex gap-2">
                @if ($hasActive)
                    <button wire:click="flushNow" type="button" class="px-4 py-2 text-sm font-medium text-indigo-700 bg-indigo-50 rounded-lg hover:bg-indigo-100">Send next batch now</button>
                @endif
                <button wire:click="openCompose" type="button" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 shadow-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    New campaign
                </button>
            </div>
        @endif
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/40">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Campaign</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Audience</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Progress</th>
                        <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($campaigns as $camp)
                        @php
                            $total = max(1, $camp->sent_count + $camp->failed_count + $camp->skipped_count + max(0, $camp->dispatches()->whereIn('status', ['queued', 'scheduled', 'sending'])->count()));
                            $badge = match ($camp->status) {
                                'sent' => 'bg-emerald-50 text-emerald-700', 'sending' => 'bg-sky-50 text-sky-700',
                                'scheduled' => 'bg-amber-50 text-amber-700', 'cancelled' => 'bg-gray-100 text-gray-500', default => 'bg-gray-100 text-gray-600',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50/50" wire:key="camp-{{ $camp->id }}">
                            <td class="px-5 py-3">
                                <p class="text-sm font-medium text-gray-800">{{ $camp->name }}</p>
                                <p class="text-xs text-gray-400">
                                    {{ collect($camp->channels)->map(fn ($ch) => \App\NotificationHub\NotificationCatalog::CHANNEL_LABELS[$ch] ?? $ch)->implode(', ') }}
                                    · {{ local_time($camp->created_at)->format('d M Y') }}{{ $camp->creator ? ' · ' . $camp->creator->name : '' }}
                                </p>
                                <p class="text-xs text-gray-500 mt-0.5 max-w-md truncate">{{ $camp->sms_body ?: $camp->push_body ?: $camp->email_subject }}</p>
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-600">
                                {{ $segments[$camp->segment]['label'] ?? $camp->segment }}
                                <span class="block text-xs text-gray-400">{{ number_format($camp->recipients_count) }} people</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $badge }}">{{ ucfirst($camp->status) }}</span>
                                @if ($camp->status === 'scheduled' && $camp->scheduled_at)
                                    <span class="block text-xs text-gray-500 mt-0.5">{{ local_time($camp->scheduled_at)->format('d M, h:i A') }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 min-w-[200px]">
                                @if (in_array($camp->status, ['sending', 'sent', 'cancelled'], true))
                                    <div class="h-2 rounded-full bg-gray-100 overflow-hidden flex">
                                        <div class="bg-emerald-500" style="width: {{ round($camp->sent_count / $total * 100) }}%"></div>
                                        <div class="bg-rose-500" style="width: {{ round($camp->failed_count / $total * 100) }}%"></div>
                                        <div class="bg-gray-300" style="width: {{ round($camp->skipped_count / $total * 100) }}%"></div>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1">
                                        <span class="text-emerald-600">{{ $camp->sent_count }} sent</span> ·
                                        <span class="text-rose-600">{{ $camp->failed_count }} failed</span> ·
                                        {{ $camp->skipped_count }} skipped
                                    </p>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('admin.notification-center.outbox', ['campaign' => $camp->id]) }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">Messages</a>
                                @if ($canSend)
                                    <button wire:click="openCompose({{ $camp->id }})" type="button" class="ml-3 text-xs font-medium text-gray-600 hover:text-gray-800">Duplicate</button>
                                    @if ($camp->status === 'scheduled')
                                        <button wire:click="sendNow({{ $camp->id }})" wire:confirm="Send this campaign now?" type="button" class="ml-3 text-xs font-medium text-emerald-600 hover:text-emerald-800">Send now</button>
                                    @endif
                                    @if (in_array($camp->status, ['scheduled', 'sending'], true))
                                        <button wire:click="cancel({{ $camp->id }})" wire:confirm="Cancel? Messages not sent yet will be dropped." type="button" class="ml-3 text-xs font-medium text-rose-600 hover:text-rose-800">Cancel</button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-16 text-center text-sm text-gray-400">No campaigns yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($campaigns->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $campaigns->links() }}</div>
        @endif
    </div>

    {{-- Compose --}}
    <div x-cloak x-data="{ open: @entangle('composeModal') }" x-show="open" x-transition
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog">
        <div class="w-full max-w-4xl bg-white rounded-2xl shadow-2xl overflow-hidden max-h-[92vh] flex flex-col" @click.outside="open = false">
            <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 shrink-0">
                <h2 class="flex-1 text-base font-semibold text-gray-900">New campaign</h2>
                <button @click="open = false" type="button" class="w-8 h-8 flex items-center justify-center rounded-full text-gray-400 hover:bg-gray-100">✕</button>
            </div>
            <div class="overflow-y-auto grid grid-cols-1 lg:grid-cols-5">
                <form wire:submit.prevent="launch" class="lg:col-span-3 px-6 py-5 space-y-4 border-r border-gray-100">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1.5">Campaign name <span class="text-red-500">*</span></label>
                        <input wire:model="c.name" type="text" placeholder="Eid offer 2026" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                        @error('c.name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Send to</label>
                            <select wire:model.live="c.segment" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                @foreach (collect($segments)->groupBy('group', true) as $g => $items)
                                    <optgroup label="{{ $g }}">
                                        @foreach ($items as $k => $s)
                                            <option value="{{ $k }}">{{ $s['label'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            @switch($c['segment'])
                                @case('customer_group')
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Group</label>
                                    <select wire:model.live="c.filters.group_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                        <option value="">Choose…</option>
                                        @foreach ($groups as $g)
                                            <option value="{{ $g->id }}">{{ $g->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('c.filters.group_id') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                                    @break
                                @case('customers_recent')
                                @case('customers_lapsed')
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Days</label>
                                    <input wire:model.live.debounce.500ms="c.filters.days" type="number" min="1" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                    @break
                                @case('customers_due')
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Owes at least (৳)</label>
                                    <input wire:model.live.debounce.500ms="c.filters.min_amount" type="number" min="1" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                    @break
                                @case('leads')
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">Stages (none = all open)</label>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($stages as $s)
                                            <label class="flex items-center gap-1 text-xs text-gray-700"><input type="checkbox" wire:model.live="c.filters.stage_ids" value="{{ $s->id }}" class="rounded border-gray-300 text-indigo-600"> {{ $s->name }}</label>
                                        @endforeach
                                    </div>
                                    @break
                            @endswitch
                        </div>
                    </div>
                    @if ($c['segment'] === 'numbers')
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">Numbers / emails <span class="text-gray-400">(one per line; "Name, 017…" works too)</span></label>
                            <textarea wire:model.live.debounce.800ms="c.filters.numbers" rows="4" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono"></textarea>
                            @error('c.filters.numbers') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="rounded-xl bg-gray-50 border border-gray-200 px-4 py-3 text-sm">
                        <span class="font-semibold text-gray-800">{{ $reach === null ? '…' : number_format($reach) }}</span> <span class="text-gray-600">people in this audience</span>
                        @if ($sampleNames)
                            <p class="text-xs text-gray-500 mt-1">e.g. {{ implode(', ', $sampleNames) }}{{ $reach > 5 ? '…' : '' }}</p>
                        @endif
                    </div>

                    <div>
                        <p class="text-xs font-medium text-gray-600 mb-1.5">Channels</p>
                        <div class="flex flex-wrap gap-4">
                            @foreach (\App\NotificationHub\NotificationCatalog::CHANNEL_LABELS as $ch => $label)
                                <label class="flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" wire:model.live="c.channels" value="{{ $ch }}" class="rounded border-gray-300 text-indigo-600"> {{ $label }}</label>
                            @endforeach
                        </div>
                        @error('c.channels') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div x-data="{ get text() { return ($wire.c && $wire.c.sms_body) || '' }, get unicode() { return /[^\x00-\x7F]/.test(this.text) }, get parts() { const n = this.text.length; if (!n) return 0; return n <= (this.unicode ? 70 : 160) ? 1 : Math.ceil(n / (this.unicode ? 67 : 153)) } }">
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-xs font-medium text-gray-600">Message</label>
                            <span class="text-[11px] text-gray-400" x-text="text.length + ' chars · ' + parts + ' SMS each'"></span>
                        </div>
                        <textarea wire:model.live.debounce.400ms="c.sms_body" rows="4" placeholder="প্রিয় {name}, ঈদ উপলক্ষে সব পণ্যে ২০% ছাড়! আজই অর্ডার করুন — {business}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"></textarea>
                        <p class="text-[11px] text-gray-400 mt-1">Placeholders: <code>{name}</code> <code>{business}</code> <code>{phone}</code> <code>{due}</code> (due segment)</p>
                        @error('c.sms_body') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>

                    @if (in_array('email', $c['channels'], true))
                        <div class="space-y-2">
                            <label class="block text-xs font-medium text-gray-600">Email subject &amp; body <span class="text-gray-400">(empty body = the message above)</span></label>
                            <input wire:model="c.email_subject" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <textarea wire:model="c.email_body" rows="4" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
                        </div>
                    @endif
                    @if (array_intersect(['push', 'database'], $c['channels']))
                        <div class="space-y-2">
                            <label class="block text-xs font-medium text-gray-600">Push / in-app title &amp; text</label>
                            <input wire:model.live.debounce.400ms="c.push_title" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            <input wire:model.live.debounce.400ms="c.push_body" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        </div>
                    @endif

                    <div class="flex flex-wrap items-center gap-4 pt-2 border-t border-gray-100">
                        <label class="flex items-center gap-1.5 text-sm text-gray-700"><input type="radio" wire:model.live="c.when" value="now" class="text-indigo-600"> Send now</label>
                        <label class="flex items-center gap-1.5 text-sm text-gray-700"><input type="radio" wire:model.live="c.when" value="later" class="text-indigo-600"> Schedule</label>
                        @if ($c['when'] === 'later')
                            <input wire:model="c.scheduled_at" type="datetime-local" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm">
                            @error('c.scheduled_at') <p class="w-full text-xs text-red-500">{{ $message }}</p> @enderror
                        @endif
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button @click="open = false" type="button" class="px-4 py-2 text-sm font-medium text-gray-600 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Cancel</button>
                        <button type="submit" wire:loading.attr="disabled"
                            wire:confirm="{{ $c['when'] === 'later' ? 'Schedule this campaign?' : 'Send to ' . number_format((int) $reach) . ' people now?' }}"
                            class="px-5 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-60">
                            {{ $c['when'] === 'later' ? 'Schedule' : 'Send campaign' }}
                        </button>
                    </div>
                </form>

                <div class="lg:col-span-2 px-6 py-5 bg-gray-50/60 space-y-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Preview</p>
                    @if ($preview['sms'])
                        <div class="rounded-2xl rounded-tl-sm bg-white border border-gray-200 px-3 py-2 text-sm text-gray-800 whitespace-pre-line">{{ $preview['sms'] }}</div>
                    @else
                        <p class="text-sm text-gray-400">Type a message to see it here.</p>
                    @endif
                    @if (array_intersect(['push', 'database'], $c['channels']))
                        <div class="rounded-xl bg-white border border-gray-200 px-3 py-2 text-sm shadow-sm">
                            <p class="font-semibold text-gray-800">{{ $preview['push_title'] }}</p>
                            <p class="text-gray-600">{{ $preview['push_body'] }}</p>
                        </div>
                        <p class="text-xs text-amber-700">Push / in-app only reach recipients who log in to this panel.</p>
                    @endif
                    <div class="text-xs text-gray-500 space-y-1 pt-3 border-t border-gray-200">
                        <p>• Messages go out in batches each minute (Settings › batch size).</p>
                        <p>• People who opted out of marketing are skipped.</p>
                        <p>• SMS during quiet hours waits until the morning.</p>
                        <p>• Each person gets it once per channel, even if listed twice.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
