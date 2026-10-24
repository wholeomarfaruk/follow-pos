<?php

namespace App\Models;

use App\Support\BdPhone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmLead extends Model
{
    use SoftDeletes;

    public const SOURCES = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'whatsapp' => 'WhatsApp',
        'website' => 'Website',
        'landing_page' => 'Landing page',
        'phone' => 'Phone call',
        'walk_in' => 'Walk-in',
        'referral' => 'Referral',
        'event' => 'Event / fair',
        'other' => 'Other',
    ];

    public const PRIORITIES = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'hot' => 'Hot',
    ];

    protected $fillable = [
        'code', 'name', 'phone', 'email', 'company', 'source', 'stage_id', 'assigned_to',
        'priority', 'expected_value', 'interest', 'address', 'tags', 'notes',
        'next_follow_up_at', 'last_contacted_at', 'lost_reason', 'won_at', 'lost_at',
        'converted_at', 'master_profile_id', 'customer_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'stage_id' => 'integer',
            'assigned_to' => 'integer',
            'tags' => 'array',
            'expected_value' => 'decimal:2',
            'next_follow_up_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CrmLead $lead) {
            if ($lead->isDirty('phone')) {
                $lead->phone = BdPhone::normalize($lead->phone) ?: null;
            }
        });

        // L-0001 style code, derived from the id so it never collides.
        static::created(function (CrmLead $lead) {
            if (! $lead->code) {
                $lead->forceFill(['code' => 'L-' . str_pad((string) $lead->id, 4, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmStage::class, 'stage_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MasterProfile::class, 'master_profile_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'lead_id');
    }

    /** Leads a user may see: everything with crm.manage, otherwise only the ones assigned to them. */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user || $user->can('crm.manage')) {
            return $query;
        }

        return $query->where('assigned_to', $user->id);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('converted_at')
            ->where(fn ($q) => $q->whereNull('stage_id')->orWhereHas('stage', fn ($s) => $s->where('is_won', false)->where('is_lost', false)));
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }

    public function isClosed(): bool
    {
        return $this->isConverted() || (bool) $this->stage?->isClosed();
    }

    public function log(string $type, ?string $body = null, array $extra = []): CrmActivity
    {
        return $this->activities()->create([
            'user_id' => auth()->id(),
            'type' => $type,
            'body' => $body !== null ? mb_substr($body, 0, 5000) : null,
        ] + $extra);
    }

    /** next_follow_up_at = the earliest open follow-up. */
    public function refreshNextFollowUp(): void
    {
        $this->forceFill(['next_follow_up_at' => $this->activities()->openFollowUps()->min('due_at')])->saveQuietly();
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? ucfirst((string) $this->source);
    }

    public function priorityClass(): string
    {
        return match ($this->priority) {
            'hot' => 'bg-rose-100 text-rose-700',
            'high' => 'bg-orange-100 text-orange-700',
            'low' => 'bg-gray-100 text-gray-500',
            default => 'bg-sky-50 text-sky-700',
        };
    }

    /** wa.me link for the lead's phone (Bangladesh numbers get the 88 prefix). */
    public function whatsappUrl(): ?string
    {
        if (! $this->phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $this->phone);

        return 'https://wa.me/' . (str_starts_with($digits, '01') ? '88' . $digits : $digits);
    }

    public function initials(): string
    {
        return MasterProfile::initialsOf($this->name);
    }
}
