<div x-data x-init="$store.pageName = { name: 'Notification Center', slug: 'notification-center' }">

    @include('livewire.admin.notification-center.partials.tabs')

    @if (! $hubOn)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            The Notification Center is switched off — nothing is being sent. <a href="{{ route('admin.notification-center.settings') }}" class="underline font-medium">Turn it on</a>.
        </div>
    @endif

    {{-- Health --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-4">
        @php
            $checks = [
                ['SMS gateway', $smsGateway ? ucfirst(str_replace('_', ' ', $smsGateway->driver_key)) : 'Not set up', (bool) $smsGateway, route('admin.settings.advance.sms-configuration.configuration')],
                ['Web push', $pushGateway ? ucfirst(str_replace('_', ' ', $pushGateway->driver_key)) : 'Not set up', (bool) $pushGateway, route('admin.settings.advance.notification-configuration.channels')],
                ['Email', $mailer ?: '—', ! in_array($mailer, ['log', 'array', ''], true), route('admin.settings.advance.email-configuration.providers')],
                ['Scheduler', $schedulerOk ? 'Running' : ($heartbeat ? 'Last ran ' . local_time($heartbeat)->diffForHumans() : 'Never ran'), $schedulerOk, null],
            ];
        @endphp
        @foreach ($checks as [$label, $value, $ok, $link])
            <div class="bg-white rounded-xl border {{ $ok ? 'border-gray-200' : 'border-amber-200' }} px-4 py-3">
                <div class="flex items-center justify-between">
                    <p class="text-xs text-gray-400">{{ $label }}</p>
                    <span class="h-2 w-2 rounded-full {{ $ok ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                </div>
                <p class="text-sm font-semibold text-gray-800 mt-1">{{ $value }}</p>
                @if (! $ok && $link)
                    <a href="{{ $link }}" class="text-xs text-indigo-600 hover:text-indigo-800">Set up →</a>
                @elseif (! $ok && $label === 'Scheduler')
                    <p class="text-xs text-amber-700">Add the cron: <code class="font-mono">* * * * * php artisan schedule:run</code></p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Volumes --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
        @foreach (['sent' => ['Sent (7 days)', 'text-emerald-600'], 'failed' => ['Failed', 'text-rose-600'], 'skipped' => ['Skipped', 'text-gray-500'], 'scheduled' => ['Waiting (quiet hours)', 'text-amber-600']] as $st => [$label, $color])
            <a href="{{ route('admin.notification-center.outbox', ['status' => $st]) }}" class="bg-white rounded-xl border border-gray-200 px-4 py-3 hover:border-indigo-300 transition">
                <p class="text-xs text-gray-400">{{ $label }}</p>
                <p class="text-2xl font-semibold mt-0.5 {{ $color }}">{{ number_format($byStatus[$st] ?? 0) }}</p>
            </a>
        @endforeach
        <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
            <p class="text-xs text-gray-400">SMS today</p>
            <p class="text-2xl font-semibold text-gray-800 mt-0.5">{{ number_format($smsToday) }}<span class="text-sm font-normal text-gray-400">{{ $smsCap ? ' / ' . number_format($smsCap) : '' }}</span></p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-1">Rules</h3>
            <p class="text-xs text-gray-400 mb-4">{{ $activeRules }} of {{ $totalRules }} switched on</p>
            <a href="{{ route('admin.notification-center.rules') }}" class="inline-flex px-4 py-2 text-sm font-medium text-indigo-700 bg-indigo-50 rounded-lg hover:bg-indigo-100">Manage rules →</a>
            <h3 class="text-sm font-semibold text-gray-900 mt-6 mb-3">Sent by channel (7 days)</h3>
            @forelse (\App\NotificationHub\NotificationCatalog::CHANNEL_LABELS as $ch => $label)
                <div class="flex items-center justify-between text-sm py-1">
                    <span class="text-gray-600">{{ $label }}</span>
                    <span class="text-gray-800 font-medium">{{ number_format($byChannel[$ch] ?? 0) }}</span>
                </div>
            @empty
            @endforelse
            <p class="text-xs text-gray-400 mt-3">{{ number_format($waiting) }} waiting in the outbox</p>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-gray-900 mb-4">Top events (7 days)</h3>
            @forelse ($byEvent as $key => $n)
                <div class="flex items-center justify-between text-sm py-1.5">
                    <span class="text-gray-600">{{ \App\NotificationHub\NotificationCatalog::event($key)['label'] ?? ($key === 'campaign' ? 'Campaigns' : ucfirst($key)) }}</span>
                    <span class="text-gray-800 font-medium">{{ number_format($n) }}</span>
                </div>
            @empty
                <p class="text-sm text-gray-400">Nothing sent yet.</p>
            @endforelse
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-gray-900">Recent failures</h3>
                <a href="{{ route('admin.notification-center.outbox', ['status' => 'failed']) }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">All →</a>
            </div>
            <div class="space-y-3">
                @forelse ($failures as $f)
                    <div class="text-sm">
                        <p class="text-gray-700"><span class="font-medium">{{ \App\NotificationHub\NotificationCatalog::CHANNEL_LABELS[$f->channel] ?? $f->channel }}</span> → {{ $f->recipient_name }} <span class="text-gray-400">{{ $f->to }}</span></p>
                        <p class="text-xs text-rose-600 truncate" title="{{ $f->error }}">{{ $f->error }}</p>
                        <p class="text-[11px] text-gray-400">{{ local_time($f->created_at)->diffForHumans() }}</p>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">No failures. 👍</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
