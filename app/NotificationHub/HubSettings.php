<?php

namespace App\NotificationHub;

use App\Models\NotificationRule;
use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * Notification Center › Settings, stored in the shared `settings` table under
 * the "notification_hub" group. Every reader goes through here so defaults
 * live in one place.
 */
class HubSettings
{
    public const GROUP = 'notification_hub';

    public const DEFAULTS = [
        'enabled' => true,
        'channel_sms' => true,
        'channel_email' => true,
        'channel_push' => true,
        'channel_database' => true,
        'quiet_enabled' => true,
        'quiet_start' => '22:00',
        'quiet_end' => '08:00',
        'sms_daily_cap' => 0,
        'sms_driver' => 'system',
        'sms_sender_id' => '',
        'batch_size' => 200,
    ];

    public static function get(string $key): mixed
    {
        return Setting::get($key, self::DEFAULTS[$key] ?? null, self::GROUP);
    }

    public static function set(string $key, mixed $value): void
    {
        Setting::set($key, $value, self::GROUP);
    }

    public static function all(): array
    {
        return collect(self::DEFAULTS)->mapWithKeys(fn ($v, $k) => [$k => self::get($k)])->all();
    }

    public static function enabled(): bool
    {
        return (bool) self::get('enabled');
    }

    public static function channelOn(string $channel): bool
    {
        return (bool) self::get("channel_{$channel}");
    }

    /**
     * An active rule of this event × audience will send SMS — the older
     * direct SMS paths (kisti SMS, EMI alert) step aside then, so nobody
     * gets the same reminder twice.
     */
    public static function sendsSms(string $eventKey, string $audience): bool
    {
        return self::enabled() && self::channelOn('sms')
            && NotificationRule::where('event_key', $eventKey)->where('audience', $audience)->where('is_active', true)->get()
                ->contains(fn (NotificationRule $r) => in_array('sms', $r->channelList(), true));
    }

    public static function smsDriver(): ?string
    {
        $driver = (string) self::get('sms_driver');

        return $driver === '' || $driver === 'system' ? null : $driver;
    }

    public static function smsDailyCap(): int
    {
        return max(0, (int) self::get('sms_daily_cap'));
    }

    public static function batchSize(): int
    {
        return min(1000, max(10, (int) self::get('batch_size')));
    }

    /**
     * When a non-urgent message created at $at should go out instead: null =
     * now (outside quiet hours), else the end of the quiet window.
     */
    public static function quietUntil(?CarbonImmutable $at = null): ?CarbonImmutable
    {
        if (! self::get('quiet_enabled')) {
            return null;
        }

        $at ??= CarbonImmutable::now();
        [$sh, $sm] = array_map('intval', explode(':', (string) self::get('quiet_start')) + [0, 0]);
        [$eh, $em] = array_map('intval', explode(':', (string) self::get('quiet_end')) + [0, 0]);

        $start = $at->setTime($sh, $sm);
        $end = $at->setTime($eh, $em);

        if ($start->equalTo($end)) {
            return null;
        }

        // Window inside one day (e.g. 13:00–14:00).
        if ($start->lessThan($end)) {
            return $at->greaterThanOrEqualTo($start) && $at->lessThan($end) ? $end : null;
        }

        // Window across midnight (e.g. 22:00–08:00).
        if ($at->greaterThanOrEqualTo($start)) {
            return $end->addDay();
        }

        return $at->lessThan($end) ? $end : null;
    }
}
