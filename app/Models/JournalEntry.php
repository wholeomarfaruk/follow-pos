<?php

namespace App\Models;

use App\Enums\Accounts\JournalEntryStatus;
use App\Enums\Accounts\TransactionType;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalEntry extends Model
{
    protected $fillable = [
        'entry_number', 'entry_date', 'description', 'status', 'transaction_type',
        'source_type', 'source_id', 'purpose', 'reversed_journal_entry_id',
        'fiscal_period_id', 'created_by', 'posted_by', 'posted_at', 'voided_by', 'voided_at',
        'voucher_ref', 'voucher_type', 'source', 'amount', 'master_profile_id', 'party_label', 'money_account_id', 'payment_method', 'payment_reference',
        'scheduled_for', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'reject_reason', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'entry_date'       => 'date',
            'scheduled_for'    => 'date',
            'status'           => JournalEntryStatus::class,
            'transaction_type' => TransactionType::class,
            'posted_at'        => 'datetime',
            'voided_at'        => 'datetime',
            'approved_at'      => 'datetime',
            'rejected_at'      => 'datetime',
            'amount'           => 'decimal:2',
            'payment_method'   => PaymentMethod::class,
            'meta'             => 'array',
        ];
    }

    /** RV-0001 style number when it has one, else the journal number. */
    public function ref(): string
    {
        return $this->voucher_ref ?: $this->entry_number;
    }

    /**
     * Records how the money moved on the entries an action just posted,
     * for actions that post through PostJournalEntry without a payment
     * method of their own (Payroll, LoanKisti, ProfileVouchers): every entry
     * after $afterId, made by the current user, that touches the cash/bank
     * account the money moved through.
     */
    public static function stampPaymentMethod(int $afterId, int $cashAccountId, ?PaymentMethod $method, ?string $reference = null): void
    {
        if (! $method) {
            return;
        }

        static::query()
            ->where('id', '>', $afterId)
            ->where('created_by', auth()->id())
            ->whereHas('lines', fn ($q) => $q->where('account_id', $cashAccountId))
            ->update(['payment_method' => $method->value, 'payment_reference' => $reference ?: null]);
    }

    public function events(): HasMany
    {
        return $this->hasMany(VoucherEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(VoucherAttachment::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MasterProfile::class, 'master_profile_id');
    }

    public function moneyAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'money_account_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_journal_entry_id');
    }

    public function reversingEntry(): HasMany
    {
        return $this->hasMany(self::class, 'reversed_journal_entry_id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function totalDebit(): float
    {
        return (float) $this->lines->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->lines->sum('credit');
    }
}
