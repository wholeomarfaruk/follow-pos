<?php

namespace App\NotificationHub\Triggers;

use App\Enums\Accounts\JournalEntryStatus;
use App\Models\Cheque;
use App\Models\JournalEntry;
use App\Models\MasterProfile;
use App\NotificationHub\NotificationHub;
use App\Services\Profiles\ProfileBalances;
use App\Support\Bdt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts Panel money moments for the Notification Hub: payment.received
 * (Collect, a cleared cheque), payment.sent (Pay, to suppliers), salary.paid
 * (Payroll › Pay) and cheque.bounced. Called inside the voucher transaction; fires after commit so a
 * rolled-back voucher never texts anyone, and only for posted vouchers (a
 * draft waiting for approval hasn't moved money yet).
 */
class AccountsNotifications
{
    public function __construct(protected NotificationHub $hub, protected ProfileBalances $balances) {}

    public function paymentReceived(MasterProfile $profile, float $amount, ?JournalEntry $entry, string $date): void
    {
        $this->after($entry, fn () => $this->hub->fire('payment.received', [
            'customer' => $profile->fresh(['user']) ?? $profile,
            'data' => [
                'amount' => $this->money($amount),
                'due' => $this->money(max(0, (float) $this->balances->forProfile($profile->id)->receivable)),
                'date' => Carbon::parse($date)->format('d M Y'),
            ],
            'related' => $entry,
            'dedupe' => $entry ? "entry:{$entry->id}" : null,
        ]));
    }

    public function paymentSent(MasterProfile $profile, float $amount, ?JournalEntry $entry, string $date): void
    {
        $this->after($entry, fn () => $this->hub->fire('payment.sent', [
            'supplier' => $profile->fresh(['user']) ?? $profile,
            'data' => ['amount' => $this->money($amount), 'date' => Carbon::parse($date)->format('d M Y')],
            'related' => $entry,
            'dedupe' => $entry ? "entry:{$entry->id}" : null,
        ]));
    }

    public function salaryPaid(MasterProfile $employee, float $amount, ?JournalEntry $entry, string $date): void
    {
        $this->after($entry, fn () => $this->hub->fire('salary.paid', [
            'employee' => $employee->fresh(['user']) ?? $employee,
            'data' => ['amount' => $this->money($amount), 'date' => Carbon::parse($date)->format('d M Y')],
            'related' => $entry,
            'dedupe' => $entry ? "entry:{$entry->id}" : null,
        ]));
    }

    /**
     * cheque.bounced: the party (customer for a cheque we received, supplier
     * for one we issued) and our own people. Fires after commit.
     */
    public function chequeBounced(Cheque $cheque): void
    {
        $profile = $cheque->profile?->fresh(['user']);

        DB::afterCommit(fn () => rescue(fn () => $this->hub->fire('cheque.bounced', [
            ($cheque->isReceived() ? 'customer' : 'supplier') => $profile,
            'data' => [
                'amount' => $this->money((float) $cheque->amount),
                'cheque_no' => $cheque->cheque_no,
                'bank' => $cheque->bank_name ?: 'Bank',
                'party' => $cheque->partyName(),
                'direction' => $cheque->isReceived() ? 'পাওয়া cheque' : 'দেওয়া cheque',
                'reason' => $cheque->bounce_reason ?: '—',
                'date' => ($cheque->bounced_at ?? now())->format('d M Y'),
            ],
            'url' => rescue(fn () => route('accounts.cheques'), null, false),
            'related' => $cheque,
            'dedupe' => "cheque-bounced:{$cheque->id}:{$cheque->bounce_count}",
        ]), null, true));
    }

    protected function after(?JournalEntry $entry, callable $send): void
    {
        if ($entry && $entry->status !== JournalEntryStatus::POSTED) {
            return;
        }

        DB::afterCommit(fn () => rescue($send, null, true));
    }

    protected function money(float $v): string
    {
        return ltrim(Bdt::money($v), '৳');
    }
}
