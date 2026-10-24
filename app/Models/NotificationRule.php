<?php

namespace App\Models;

use App\NotificationHub\NotificationCatalog;
use Illuminate\Database\Eloquent\Model;

/**
 * One event × audience of the Notification Hub: on/off, channels, the texts
 * per channel and, for scheduled events, timing. Rows are created from the
 * NotificationCatalog defaults (NotificationCatalog::syncRules()).
 */
class NotificationRule extends Model
{
    protected $fillable = [
        'event_key', 'audience', 'is_active', 'channels',
        'sms_body', 'email_subject', 'email_body', 'push_title', 'push_body',
        'offset_days', 'send_time', 'staff_role', 'conditions',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'channels' => 'array',
            'conditions' => 'array',
            'offset_days' => 'integer',
        ];
    }

    public function event(): ?array
    {
        return NotificationCatalog::event($this->event_key);
    }

    /** @return array<int, string> */
    public function channelList(): array
    {
        return array_values(array_intersect((array) $this->channels, NotificationCatalog::CHANNELS));
    }

    public function condition(string $key, mixed $default = null): mixed
    {
        return data_get($this->conditions, $key, $default);
    }
}
