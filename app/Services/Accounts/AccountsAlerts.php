<?php

namespace App\Services\Accounts;

use App\Enums\Accounts\JournalEntryStatus;
use App\Models\JournalEntry;
use App\Models\LoanRepaymentSchedule;
use App\NotificationHub\HubSettings;
use App\Support\AccountsSettings;
use App\Support\Bdt;
use Carbon\CarbonImmutable;

/**
 * Settings › SMS & reminders › "নিজেদের alert", sent out instead of only
 * shown on the dashboard: drafts older than 48 hours → accountants (email),
 * loan EMI due in 3 days → owners (SMS), and the 21:00 day summary → owners
 * (SMS). Delivery goes through ReportDelivery, so a missing mail / SMS setup
 * only logs.
 */
class AccountsAlerts
{
    public function __construct(protected ReportDelivery $delivery, protected DailyReport $daily) {}

    /** @return array{stale: int, emi: int} how many recipients each alert reached */
    public function morning(): array
    {
        $out = ['stale' => 0, 'emi' => 0];
        $business = AccountsSettings::businessName();

        if (AccountsSettings::alert('stale_voucher')) {
            $stale = JournalEntry::where('status', JournalEntryStatus::DRAFT)->where('created_at', '<', now()->subHours(48))->count();
            if ($stale) {
                $text = "{$stale}টা voucher ৪৮ ঘণ্টার বেশি post হয়নি — Posting Pending দেখুন: " . route('accounts.posting') . " — {$business}";
                $out['stale'] = $this->delivery->send('accountant', ['email'], 'Vouchers waiting to post', $text)['email'] ?? 0;
            }
        }

        // Notification Center › loan.emi_due (owner, SMS) sends this instead when it is on.
        if (AccountsSettings::alert('emi_3_days') && ! HubSettings::sendsSms('loan.emi_due', 'owner')) {
            $day = CarbonImmutable::today()->addDays(3);
            $due = LoanRepaymentSchedule::with('loan:id,name,lender')
                ->whereIn('status', ['pending', 'overdue'])
                ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
                ->whereDate('due_date', $day->toDateString())
                ->get();

            if ($due->isNotEmpty()) {
                $lines = $due->map(fn (LoanRepaymentSchedule $s) => ($s->loan?->lender ?: $s->loan?->name) . ' ' . Bdt::money($s->payable() - (float) $s->paid_amount))->implode(', ');
                $text = 'Loan EMI ৩ দিন পর (' . $day->format('d M') . "): {$lines} — {$business}";
                $out['emi'] = $this->delivery->send('owners', ['sms'], 'Loan EMI in 3 days', $text)['sms'] ?? 0;
            }
        }

        return $out;
    }

    /** The day's summary to owners by SMS; null when the alert is off. */
    public function dailySummary(): ?int
    {
        if (! AccountsSettings::alert('daily_summary')) {
            return null;
        }

        $text = $this->daily->summaryText($this->daily->data(CarbonImmutable::today()));

        return $this->delivery->send('owners', ['sms'], 'Daily summary', $text)['sms'] ?? 0;
    }
}
