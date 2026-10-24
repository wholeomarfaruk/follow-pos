<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One message to one recipient over one channel: the hub's outbox and its
 * log. status: scheduled (waiting for scheduled_at, e.g. quiet hours) →
 * queued → sent | failed; skipped when there was nothing to send to.
 */
class NotificationDispatch extends Model
{
    public const STATUSES = ['scheduled', 'queued', 'sent', 'failed', 'skipped'];

    protected $fillable = [
        'rule_id', 'campaign_id', 'event_key', 'audience', 'channel',
        'recipient_type', 'recipient_id', 'recipient_name', 'to',
        'subject', 'body', 'url', 'status', 'error', 'provider', 'dedupe_key',
        'related_type', 'related_id', 'attempts', 'scheduled_at', 'sent_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NotificationCampaign::class);
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'sent' => 'emerald',
            'failed' => 'rose',
            'skipped' => 'gray',
            'scheduled' => 'amber',
            default => 'sky',
        };
    }
}
