<?php

namespace App\Livewire\Admin\NotificationCenter;

use App\Models\NotificationDispatch;
use App\Models\NotificationRule;
use App\Models\PushGatewayConfig;
use App\Models\SmsGatewayConfig;
use App\NotificationHub\HubSettings;
use App\NotificationHub\NotificationCatalog;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Notification Center › Overview: is everything wired (gateways, scheduler),
 * what went out lately, and what failed.
 */
class Overview extends Component
{
    use Concerns\GuardsNotificationCenter;

    public function mount(): void
    {
        $this->guardView();
        NotificationCatalog::syncRules();
    }

    public function render()
    {
        $since = now()->subDays(7);
        $byStatus = NotificationDispatch::where('created_at', '>=', $since)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $byChannel = NotificationDispatch::where('created_at', '>=', $since)->where('status', 'sent')->selectRaw('channel, COUNT(*) as n')->groupBy('channel')->pluck('n', 'channel');
        $byEvent = NotificationDispatch::where('created_at', '>=', $since)->where('status', 'sent')->selectRaw('event_key, COUNT(*) as n')->groupBy('event_key')->orderByDesc('n')->limit(8)->pluck('n', 'event_key');

        $heartbeat = Cache::get('scheduler_last_ran_at');

        return view('livewire.admin.notification-center.overview', [
            'byStatus' => $byStatus,
            'byChannel' => $byChannel,
            'byEvent' => $byEvent,
            'smsToday' => NotificationDispatch::where('channel', 'sms')->whereIn('status', ['sent', 'queued', 'sending'])->where('created_at', '>=', today())->count(),
            'smsCap' => HubSettings::smsDailyCap(),
            'activeRules' => NotificationRule::where('is_active', true)->count(),
            'totalRules' => NotificationRule::count(),
            'hubOn' => HubSettings::enabled(),
            'smsGateway' => rescue(fn () => SmsGatewayConfig::active(), null, false),
            'pushGateway' => rescue(fn () => PushGatewayConfig::active(), null, false),
            'mailer' => (string) config('mail.default'),
            'schedulerOk' => $heartbeat && now()->diffInMinutes($heartbeat, true) < 5,
            'heartbeat' => $heartbeat,
            'waiting' => NotificationDispatch::whereIn('status', ['queued', 'scheduled'])->count(),
            'failures' => NotificationDispatch::with('rule')->where('status', 'failed')->latest('id')->limit(8)->get(),
        ])->layout('layouts.admin.admin');
    }
}
