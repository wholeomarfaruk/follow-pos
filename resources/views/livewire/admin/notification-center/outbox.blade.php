<div x-data x-init="$store.pageName = { name: 'Notification Center · Outbox', slug: 'notification-outbox' }">

    @include('livewire.admin.notification-center.partials.tabs')

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 px-5 py-4 border-b border-gray-100">
            <div class="relative flex-1 min-w-[200px]">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"/></svg>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Phone, email, name or text…" class="w-full pl-9 pr-3 py-2 text-sm rounded-lg border border-gray-300 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
            </div>
            <select wire:model.live="status" class="text-sm rounded-lg border border-gray-300 px-3 py-2">
                <option value="">All statuses</option>
                @foreach (\App\Models\NotificationDispatch::STATUSES as $s)
                    <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                @endforeach
            </select>
            <select wire:model.live="channel" class="text-sm rounded-lg border border-gray-300 px-3 py-2">
                <option value="">All channels</option>
                @foreach (\App\NotificationHub\NotificationCatalog::CHANNEL_LABELS as $ch => $label)
                    <option value="{{ $ch }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="event" class="text-sm rounded-lg border border-gray-300 px-3 py-2">
                <option value="">All events</option>
                @foreach ($events as $e)
                    <option value="{{ $e }}">{{ \App\NotificationHub\NotificationCatalog::event($e)['label'] ?? ucfirst($e) }}</option>
                @endforeach
            </select>
            @if ($canManage && $status === 'failed')
                <button wire:click="retryAllFailed" wire:confirm="Retry every failed message from the last 3 days?" type="button" class="px-3 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">Retry all failed</button>
            @endif
            @if ($campaign)
                <button wire:click="$set('campaign', '')" type="button" class="text-xs text-indigo-600">Campaign #{{ $campaign }} ✕</button>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50/40">
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">When</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Event</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">To</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Channel</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Message</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($items as $d)
                        @php
                            $badge = match ($d->status) {
                                'sent' => 'bg-emerald-50 text-emerald-700', 'failed' => 'bg-rose-50 text-rose-700',
                                'skipped' => 'bg-gray-100 text-gray-500', 'scheduled' => 'bg-amber-50 text-amber-700', default => 'bg-sky-50 text-sky-700',
                            };
                            $eventLabel = $d->campaign ? 'Campaign · ' . $d->campaign->name : (\App\NotificationHub\NotificationCatalog::event($d->event_key)['label'] ?? ucfirst($d->event_key));
                        @endphp
                        <tr class="hover:bg-gray-50/50 cursor-pointer" wire:click="toggle({{ $d->id }})" wire:key="d-{{ $d->id }}">
                            <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap">{{ local_time($d->created_at)->format('d M, h:i A') }}</td>
                            <td class="px-5 py-3 text-sm text-gray-700">
                                {{ $eventLabel }}
                                @if ($d->audience && ! $d->campaign)
                                    <span class="block text-xs text-gray-400">→ {{ \App\NotificationHub\NotificationCatalog::AUDIENCES[$d->audience] ?? $d->audience }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-sm">
                                <span class="text-gray-800">{{ $d->recipient_name }}</span>
                                <span class="block text-xs text-gray-400">{{ $d->to ?: '—' }}</span>
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-600">{{ \App\NotificationHub\NotificationCatalog::CHANNEL_LABELS[$d->channel] ?? $d->channel }}</td>
                            <td class="px-5 py-3 text-sm text-gray-600 max-w-xs truncate">{{ $d->subject ? $d->subject . ' — ' : '' }}{{ $d->body }}</td>
                            <td class="px-5 py-3">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium {{ $badge }}">{{ ucfirst($d->status) }}</span>
                                @if ($d->status === 'scheduled' && $d->scheduled_at)
                                    <span class="block text-[11px] text-gray-400">{{ local_time($d->scheduled_at)->format('d M, h:i A') }}</span>
                                @elseif ($d->error)
                                    <span class="block text-[11px] text-rose-500 max-w-[180px] truncate" title="{{ $d->error }}">{{ $d->error }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right" @click.stop>
                                @if ($canManage && in_array($d->status, ['failed', 'skipped'], true) && $d->to)
                                    <button wire:click="retry({{ $d->id }})" type="button" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">Retry</button>
                                @endif
                            </td>
                        </tr>
                        @if ($openId === $d->id)
                            <tr class="bg-gray-50/70" wire:key="d-open-{{ $d->id }}">
                                <td colspan="7" class="px-5 py-4">
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                        <div class="md:col-span-2">
                                            @if ($d->subject)<p class="font-semibold text-gray-800 mb-1">{{ $d->subject }}</p>@endif
                                            <p class="text-gray-700 whitespace-pre-line">{{ $d->body }}</p>
                                            @if ($d->url)<a href="{{ $d->url }}" class="text-xs text-indigo-600 break-all">{{ $d->url }}</a>@endif
                                        </div>
                                        <dl class="text-xs space-y-1 text-gray-500">
                                            <div>Attempts: <span class="text-gray-700">{{ $d->attempts }}</span></div>
                                            @if ($d->provider)<div>Provider: <span class="text-gray-700">{{ $d->provider }}</span></div>@endif
                                            @if ($d->sent_at)<div>Sent: <span class="text-gray-700">{{ local_time($d->sent_at)->format('d M Y, h:i:s A') }}</span></div>@endif
                                            @if ($d->error)<div class="text-rose-600">Error: {{ $d->error }}</div>@endif
                                            @if ($d->recipient_type === \App\Models\MasterProfile::class && $d->recipient_id && Route::has('accounts.profiles.show'))
                                                <a href="{{ route('accounts.profiles.show', $d->recipient_id) }}" class="text-indigo-600">Open profile →</a>
                                            @elseif ($d->recipient_type === \App\Models\CrmLead::class && $d->recipient_id)
                                                <a href="{{ route('admin.crm.leads.show', $d->recipient_id) }}" class="text-indigo-600">Open lead →</a>
                                            @endif
                                        </dl>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="7" class="px-5 py-16 text-center text-sm text-gray-400">Nothing here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($items->hasPages())
            <div class="px-5 py-3 border-t border-gray-100">{{ $items->links() }}</div>
        @endif
    </div>
</div>
