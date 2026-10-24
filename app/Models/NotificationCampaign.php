<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A manual broadcast (offers, announcements) to a segment; see
 * NotificationHub\Segments for what a segment can be.
 */
class NotificationCampaign extends Model
{
    protected $fillable = [
        'name', 'segment', 'segment_filters', 'channels',
        'sms_body', 'email_subject', 'email_body', 'push_title', 'push_body',
        'status', 'scheduled_at', 'started_at', 'finished_at',
        'recipients_count', 'sent_count', 'failed_count', 'skipped_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'segment_filters' => 'array',
            'channels' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(NotificationDispatch::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Re-count from the dispatches, which change status after the campaign row was written. */
    public function refreshCounts(): void
    {
        $counts = $this->dispatches()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        $this->forceFill([
            'sent_count' => (int) ($counts['sent'] ?? 0),
            'failed_count' => (int) ($counts['failed'] ?? 0),
            'skipped_count' => (int) ($counts['skipped'] ?? 0),
        ])->save();
    }
}
