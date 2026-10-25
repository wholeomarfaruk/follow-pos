<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * direction: received (a party gave us) | issued (we gave a party).
 * status: pending → deposited (received only) → cleared | bounced | cancelled.
 * Clearing makes the receipt / payment voucher (App\Services\Accounts\Cheques).
 */
class Cheque extends Model
{
    public const DIRECTIONS = ['received' => 'পেলাম · Received', 'issued' => 'দিলাম · Issued'];

    public const STATUSES = [
        'pending'   => 'Pending',
        'deposited' => 'Deposited',
        'cleared'   => 'Cleared',
        'bounced'   => 'Bounced',
        'cancelled' => 'Cancelled',
    ];

    /** Not cashed yet — what reminders and "in hand" totals count. */
    public const OPEN = ['pending', 'deposited'];

    protected $fillable = [
        'direction', 'cheque_no', 'bank_name', 'branch', 'master_profile_id', 'party_label', 'amount',
        'cheque_date', 'due_date', 'account_id', 'ledger_id', 'status', 'journal_entry_id',
        'deposited_at', 'cleared_at', 'bounced_at', 'bounce_reason', 'bounce_count', 'note', 'created_by',
        'order_payment_id', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cheque_date' => 'date',
            'due_date' => 'date',
            'deposited_at' => 'date',
            'cleared_at' => 'date',
            'bounced_at' => 'date',
            'bounce_count' => 'integer',
            'meta' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MasterProfile::class, 'master_profile_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'ledger_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** The pending order payment this cheque pays (Sales › Order › Add payment). */
    public function orderPayment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isReceived(): bool
    {
        return $this->direction === 'received';
    }

    /** An advance to a supplier (Purchase › Advance, profile › Advance): clears into an open supplier advance. */
    public function isAdvance(): bool
    {
        return ($this->meta['kind'] ?? null) === 'supplier_advance';
    }

    public function partyName(): string
    {
        return $this->profile?->display_name ?? ($this->party_label ?: '—');
    }

    /** "DBBL · 004512" */
    public function label(): string
    {
        return trim(($this->bank_name ? "{$this->bank_name} · " : '') . $this->cheque_no);
    }
}
