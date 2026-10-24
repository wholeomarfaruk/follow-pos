<?php

namespace App\NotificationHub\Triggers;

use App\Enums\Accounts\JournalEntryStatus;
use App\Models\JournalEntry;
use App\Models\MasterProfile;
use App\NotificationHub\NotificationHub;
use App\Services\Profiles\ProfileBalances;
use App\Support\Bdt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accounts Panel money moments for the Notification Hub: payment.received
 * (Collect), payment.sent (Pay, to suppliers) and salary.paid (Payroll ›
 * Pay). Called inside the voucher transaction; fires after commit so a
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
