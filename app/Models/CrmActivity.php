<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead's timeline entry. With due_at it is a follow-up task, done when
 * done_at is set.
 */
class CrmActivity extends Model
{
    public const TYPES = [
        'note' => 'Note',
        'call' => 'Call',
        'sms' => 'SMS',
        'email' => 'Email',
        'whatsapp' => 'WhatsApp',
        'meeting' => 'Meeting / visit',
        'follow_up' => 'Follow-up',
        'stage' => 'Stage change',
        'system' => 'System',
    ];

    /** Types staff can log by hand from the lead page. */
    public const LOGGABLE = ['note', 'call', 'sms', 'email', 'whatsapp', 'meeting'];

    public const OUTCOMES = [
        'answered' => 'Answered',
        'no_answer' => 'No answer',
        'busy' => 'Busy / call back',
        'interested' => 'Interested',
        'not_interested' => 'Not interested',
        'wrong_number' => 'Wrong number',
    ];

    protected $fillable = ['lead_id', 'user_id', 'type', 'body', 'outcome', 'due_at', 'done_at', 'meta'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'done_at' => 'datetime', 'meta' => 'array', 'user_id' => 'integer', 'lead_id' => 'integer'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpenFollowUps(Builder $query): Builder
    {
        return $query->whereNotNull('due_at')->whereNull('done_at');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
