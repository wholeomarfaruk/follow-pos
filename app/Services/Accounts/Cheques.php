<?php

namespace App\Services\Accounts;

use App\Actions\Accounts\PostOrderPayment;
use App\Actions\Profiles\ProfileVouchers;
use App\Enums\PaymentMethod;
use App\Enums\Sales\PaymentStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\JournalEntry;
use App\Models\MasterProfile;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\NotificationHub\Triggers\AccountsNotifications;
use App\Services\Profiles\LedgerAccounts;
use App\Support\Bdt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Accounts Panel › Cheques. A cheque is only a promise until the bank pays
 * it: registering one posts nothing, so the party's due stays as it is.
 * Clearing makes the receipt (Dr bank / Cr ledger) or payment (Dr ledger /
 * Cr bank) voucher through the normal workflow — posted straight away when
 * the user may, otherwise it waits in Posting Pending like any voucher. A
 * cheque taken on an order instead turns its pending order payment paid,
 * which posts the way an order payment always does.
 *
 *   pending ──deposit──► deposited ──clear──► cleared (voucher / paid order payment)
 *      │                    │
 *      └──── bounce / cancel┘      bounced ──reschedule──► pending
 */
class Cheques
{
    /** Ledgers whose balance is per party: a cheque against them needs a profile. */
    public const PARTY_LEDGERS = [LedgerAccounts::RECEIVABLE, LedgerAccounts::PAYABLE, LedgerAccounts::SUPPLIER_ADVANCE];

    /** Income ledger a bounce fee charged to the customer goes to. */
    public const BOUNCE_FEE_LEDGER = '4150';

    public function __construct(protected Vouchers $vouchers, protected LedgerAccounts $ledgers) {}

    /**
     * @param  array{direction: string, cheque_no: string, bank_name?: ?string, branch?: ?string, profile_id?: ?int,
     *               party_label?: ?string, amount: float, cheque_date: string, due_date?: ?string,
     *               account_id: ?int, ledger_id: ?int, note?: ?string, order_payment_id?: ?int, meta?: ?array}  $d
     */
    public function create(array $d, ?User $by = null): Cheque
    {
        abort_unless(isset(Cheque::DIRECTIONS[$d['direction']]), 422, 'Unknown cheque direction.');

        $profile = ! empty($d['profile_id']) ? MasterProfile::find($d['profile_id']) : null;
        $label = trim((string) ($d['party_label'] ?? '')) ?: null;
        $forOrder = ! empty($d['order_payment_id']);

        if (! $profile && ! $label) {
            throw new RuntimeException('কার cheque — profile বেছে নিন অথবা নাম লিখুন।');
        }
        // An order cheque clears through its order payment, not this ledger.
        $ledgerCode = Account::whereKey($d['ledger_id'] ?? 0)->value('code');
        if (! $forOrder && ! $profile && in_array($ledgerCode, self::PARTY_LEDGERS, true)) {
            throw new RuntimeException('Receivable / payable / advance-এর cheque-এ profile লাগবে — না হলে বাকি কমবে না।');
        }

        // An issued cheque against Supplier Advance clears into an open advance (wherever it was entered).
        $meta = ($d['meta'] ?? null) ?: [];
        if (! $forOrder && $d['direction'] === 'issued' && $ledgerCode === LedgerAccounts::SUPPLIER_ADVANCE) {
            $meta['kind'] = 'supplier_advance';
        }

        $bank = trim((string) ($d['bank_name'] ?? '')) ?: null;
        $this->assertNotDuplicate($d['direction'], $d['cheque_no'], $bank, $profile?->id);

        $cheque = Cheque::create([
            'direction'         => $d['direction'],
            'cheque_no'         => trim($d['cheque_no']),
            'bank_name'         => $bank,
            'branch'            => trim((string) ($d['branch'] ?? '')) ?: null,
            'master_profile_id' => $profile?->id,
            'party_label'       => $profile ? null : $label,
            'amount'            => round((float) $d['amount'], 2),
            'cheque_date'       => $d['cheque_date'],
            'due_date'          => ($d['due_date'] ?? null) ?: $d['cheque_date'],
            'account_id'        => ($d['account_id'] ?? null) ?: null,
            'ledger_id'         => ($d['ledger_id'] ?? null) ?: null,
            'status'            => 'pending',
            'order_payment_id'  => $forOrder ? (int) $d['order_payment_id'] : null,
            'note'              => ($d['note'] ?? null) ?: null,
            'meta'              => $meta ?: null,
            'created_by'        => ($by ?? auth()->user())?->id,
        ]);

        $this->log($cheque, ($cheque->isReceived() ? 'Cheque received ' : 'Cheque issued ') . $this->describe($cheque) . ' · date ' . $cheque->due_date->format('d M Y'));

        return $cheque;
    }

    /** A received cheque handed to the bank. */
    public function deposit(Cheque $cheque, ?string $date = null): void
    {
        if (! $cheque->isReceived() || $cheque->status !== 'pending') {
            throw new RuntimeException('Only a received cheque in hand can be deposited.');
        }

        $cheque->update(['status' => 'deposited', 'deposited_at' => $date ?? today()->toDateString()]);
        $this->log($cheque, 'Cheque deposited ' . $this->describe($cheque));
    }

    /**
     * The bank paid it: make the receipt / payment voucher (dated $date) and
     * try to post it when $post — or, for an order cheque, mark its order
     * payment paid. Returns the entry (null when Accounts is off for an order
     * cheque) and, when a voucher could not be posted, why (it then waits in
     * Posting Pending).
     *
     * @return array{0: ?JournalEntry, 1: ?string}
     */
    public function clear(Cheque $cheque, string $date, int $accountId, bool $post = true, ?User $by = null): array
    {
        if (CarbonImmutable::parse($date)->isAfter(today())) {
            throw new RuntimeException('Clear-এর তারিখ ভবিষ্যতের হতে পারে না।');
        }

        [$entry, $isVoucher] = DB::transaction(function () use ($cheque, $date, $accountId, $by) {
            $c = Cheque::whereKey($cheque->id)->lockForUpdate()->firstOrFail();
            if (! $c->isOpen()) {
                throw new RuntimeException("Cheque {$c->label()} is " . strtolower(Cheque::STATUSES[$c->status] ?? $c->status) . '.');
            }

            if ($c->order_payment_id) {
                $entry = $this->payOrder($c, $date, $accountId);
                $c->update(['status' => 'cleared', 'cleared_at' => $date, 'account_id' => $accountId, 'journal_entry_id' => $entry?->id]);
                $this->log($c, 'Cheque cleared ' . $this->describe($c) . ' → order payment paid' . ($entry ? " ({$entry->ref()})" : ''));

                return [$entry, false];
            }

            if ($c->isAdvance()) {
                $entry = $this->payAdvance($c, $date, $accountId);
                $c->update(['status' => 'cleared', 'cleared_at' => $date, 'account_id' => $accountId, 'journal_entry_id' => $entry?->id]);
                $this->log($c, 'Cheque cleared ' . $this->describe($c) . ' → supplier advance' . ($entry ? " ({$entry->ref()})" : ''));

                return [$entry, false];
            }

            $entry = $this->clearingVoucher($c, $date, $accountId, $by);
            $c->update(['status' => 'cleared', 'cleared_at' => $date, 'account_id' => $accountId, 'journal_entry_id' => $entry->id]);
            $this->log($c, 'Cheque cleared ' . $this->describe($c) . " → {$entry->ref()}");

            return [$entry, true];
        });

        $error = null;
        if ($post && $isVoucher) {
            try {
                $entry = $this->vouchers->post($entry, $by);
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return [$entry, $error];
    }

    /** Receipt (Dr bank / Cr ledger) or payment (Dr ledger / Cr bank), as a draft. */
    protected function clearingVoucher(Cheque $c, string $date, int $accountId, ?User $by): JournalEntry
    {
        if (! $c->ledger_id) {
            throw new RuntimeException('এই cheque-এর হিসাব (ledger) নেই — edit করে ledger দিন।');
        }

        $profile = $c->profile;
        $amount = round((float) $c->amount, 2);
        $party = $this->vouchers->partyFields((int) $c->ledger_id, $profile);

        $lines = $c->isReceived()
            ? [
                ['account_id' => $accountId, 'debit' => $amount],
                ['account_id' => (int) $c->ledger_id, 'credit' => $amount] + $party,
            ]
            : [
                ['account_id' => (int) $c->ledger_id, 'debit' => $amount, 'memo' => $c->partyName()] + $party,
                ['account_id' => $accountId, 'credit' => $amount],
            ];

        return $this->vouchers->draft([
            'type'              => $c->isReceived() ? 'receipt' : 'payment',
            'date'              => $date,
            'description'       => ($c->isReceived() ? 'Cheque received · ' : 'Cheque paid · ') . $c->label() . ($c->note ? " — {$c->note}" : ''),
            'lines'             => $lines,
            'profile_id'        => $profile?->id,
            'party_label'       => $profile ? null : $c->party_label,
            'money_account_id'  => $accountId,
            'payment_method'    => PaymentMethod::CHEQUE->value,
            'payment_reference' => $c->cheque_no,
            'source_type'       => Cheque::class,
            'source_id'         => $c->id,
            'purpose'           => 'cheque_cleared',
        ], [], $by);
    }

    /** An order cheque cleared: its pending payment turns paid and posts like any order payment. */
    protected function payOrder(Cheque $c, string $date, int $accountId): ?JournalEntry
    {
        $payment = OrderPayment::whereKey($c->order_payment_id)->lockForUpdate()->first();
        $order = $payment?->order;

        if (! $payment || ! $order) {
            throw new RuntimeException('এই cheque-এর order payment আর নেই।');
        }
        if ($payment->status === PaymentStatus::PAID) {
            throw new RuntimeException("Order #{$order->id}-এর এই payment আগেই paid।");
        }

        $payment->update([
            'status'          => PaymentStatus::PAID,
            'paid_at'         => CarbonImmutable::parse($date),
            'cash_account_id' => $accountId,
            'transaction_id'  => $payment->transaction_id ?: $c->cheque_no,
        ]);
        $this->syncOrder($order);

        activity('sales')->causedBy(auth()->user())->performedOn($order)->event('updated')
            ->log("Cheque {$c->label()} cleared — payment of {$payment->amount} received for Order #{$order->id}");

        return app(PostOrderPayment::class)->handle($order->fresh('customer'), $payment->fresh('cashAccount'), $date);
    }

    /**
     * A supplier-advance cheque cleared: the advance is booked the way an
     * advance always is (Purchase ledger row + Dr Supplier Advance / Cr bank
     * + an open advance to apply against later bills).
     */
    protected function payAdvance(Cheque $c, string $date, int $accountId): ?JournalEntry
    {
        if (! $c->profile) {
            throw new RuntimeException('Supplier advance-এর cheque-এ supplier profile লাগবে।');
        }

        $advance = app(ProfileVouchers::class)->supplierAdvance(
            $c->profile, round((float) $c->amount, 2), $accountId, $date,
            $c->note ?: "Advance by cheque {$c->label()}", PaymentMethod::CHEQUE, $c->cheque_no,
        );

        return $advance->journalEntry;
    }

    /**
     * Fix a cheque that hasn't cleared yet: number, bank, amount, cheque date
     * (the cash / release date moves with it unless it was rescheduled), note.
     * An order cheque's pending payment follows.
     *
     * @param  array{cheque_no: string, bank_name?: ?string, amount: float, cheque_date: string, note?: ?string}  $d
     */
    public function amend(Cheque $cheque, array $d): void
    {
        if (! $cheque->isOpen() && $cheque->status !== 'bounced') {
            throw new RuntimeException('Clear / cancel হওয়া cheque বদলানো যায় না।');
        }

        $bank = trim((string) ($d['bank_name'] ?? '')) ?: null;
        $this->assertNotDuplicate($cheque->direction, $d['cheque_no'], $bank, $cheque->master_profile_id, $cheque->id);

        DB::transaction(function () use ($cheque, $d, $bank) {
            $followsDate = $cheque->due_date->isSameDay($cheque->cheque_date);
            $before = $this->describe($cheque);

            $cheque->update([
                'cheque_no'   => trim($d['cheque_no']),
                'bank_name'   => $bank,
                'amount'      => round((float) $d['amount'], 2),
                'cheque_date' => $d['cheque_date'],
                'note'        => array_key_exists('note', $d) ? (($d['note'] ?? null) ?: null) : $cheque->note,
            ] + ($followsDate ? ['due_date' => $d['cheque_date']] : []));

            $payment = $cheque->orderPayment;
            if ($payment && $payment->status !== PaymentStatus::PAID) {
                $payment->update(['amount' => $cheque->amount, 'transaction_id' => $cheque->cheque_no]);
            }

            $this->log($cheque, "Cheque edited {$before} → " . $this->describe($cheque->fresh()));
        });
    }

    /** Order totals and payment status after one of its payments changed. */
    protected function syncOrder(Order $order): void
    {
        $order->recalculateTotals();
        $order->refresh();

        // A refunded order keeps its status; the refund flow owns it.
        if ($order->payment_status === PaymentStatus::REFUNDED) {
            return;
        }

        $order->update(['payment_status' => match (true) {
            (float) $order->paid_amount <= 0 => PaymentStatus::PENDING,
            (float) $order->due_amount > 0 => PaymentStatus::PARTIAL,
            default => PaymentStatus::PAID,
        }]);
    }

    /** An order cheque that won't be paid (bounce / cancel) fails its payment; presented again, it waits again. */
    protected function setOrderPayment(Cheque $cheque, PaymentStatus $status): void
    {
        $payment = $cheque->orderPayment;
        if (! $payment || $payment->status === PaymentStatus::PAID) {
            return;
        }

        $payment->update(['status' => $status, 'paid_at' => null]);
        if ($payment->order) {
            $this->syncOrder($payment->order);
        }
    }

    /** Back to open while its voucher hasn't posted (the waiting draft is deleted). */
    public function undoClear(Cheque $cheque, ?User $by = null): void
    {
        if ($cheque->status !== 'cleared') {
            throw new RuntimeException('Only a cleared cheque can be undone.');
        }

        $entry = $cheque->entry;
        if ($entry && ! $entry->status->isPending()) {
            throw new RuntimeException("{$entry->ref()} is {$entry->status->label()} — Transactions থেকে reverse করুন।");
        }

        DB::transaction(function () use ($cheque, $entry, $by) {
            if ($entry) {
                $this->vouchers->delete($entry, $by);
            }

            // An order cheque cleared with Accounts off: its payment goes back to waiting.
            if ($cheque->orderPayment?->status === PaymentStatus::PAID) {
                $cheque->orderPayment->update(['status' => PaymentStatus::PENDING, 'paid_at' => null]);
                if ($cheque->orderPayment->order) {
                    $this->syncOrder($cheque->orderPayment->order);
                }
            }

            $cheque->update(['status' => $cheque->deposited_at ? 'deposited' : 'pending', 'cleared_at' => null, 'journal_entry_id' => null]);
            $this->log($cheque, 'Cheque clear undone ' . $this->describe($cheque));
        });
    }

    /**
     * The bank returned it unpaid: the due stays and the party is told
     * (cheque.bounced). Optional drafts: what our bank charged us (expense)
     * and, for a cheque we received, a fee charged to the customer. Returns
     * the refs of those drafts.
     *
     * @return list<string>
     */
    public function bounce(Cheque $cheque, string $date, ?string $reason = null, float $bankCharge = 0, float $partyFee = 0): array
    {
        if (! $cheque->isOpen()) {
            throw new RuntimeException('Only a pending / deposited cheque can bounce.');
        }
        if ($bankCharge > 0 && ! $cheque->account_id) {
            throw new RuntimeException('Bank charge-এর জন্য cheque-এর bank account লাগবে।');
        }
        if ($partyFee > 0 && (! $cheque->isReceived() || ! $cheque->profile)) {
            throw new RuntimeException('Bounce fee শুধু profile-সহ পাওয়া cheque-এ ধরা যায়।');
        }

        $reason = trim((string) $reason);

        return DB::transaction(function () use ($cheque, $date, $reason, $bankCharge, $partyFee) {
            $cheque->update([
                'status'        => 'bounced',
                'bounced_at'    => $date,
                'bounce_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'bounce_count'  => $cheque->bounce_count + 1,
            ]);
            $this->setOrderPayment($cheque, PaymentStatus::FAILED);

            $drafts = [];
            $n = $cheque->bounce_count;

            if ($bankCharge > 0) {
                $charge = round($bankCharge, 2);
                $drafts[] = $this->vouchers->draft([
                    'type'             => 'expense',
                    'date'             => $date,
                    'description'      => "Cheque bounce charge · {$cheque->label()} · {$cheque->partyName()}",
                    'party_label'      => $cheque->partyName(),
                    'money_account_id' => (int) $cheque->account_id,
                    'lines'            => [
                        ['account_id' => $this->ledgers->require(ExpenseCategories::CHARGES), 'debit' => $charge, 'memo' => "Bounce · {$cheque->label()}"],
                        ['account_id' => (int) $cheque->account_id, 'credit' => $charge],
                    ],
                    'source_type'      => Cheque::class,
                    'source_id'        => $cheque->id,
                    'purpose'          => "cheque_bank_charge_{$n}",
                ])->ref();
            }

            if ($partyFee > 0) {
                $fee = round($partyFee, 2);
                $ar = $this->ledgers->require(LedgerAccounts::RECEIVABLE);
                $drafts[] = $this->vouchers->draft([
                    'type'        => 'journal',
                    'date'        => $date,
                    'description' => "Cheque bounce fee · {$cheque->label()} · {$cheque->partyName()}",
                    'profile_id'  => $cheque->master_profile_id,
                    'lines'       => [
                        ['account_id' => $ar, 'debit' => $fee] + $this->vouchers->partyFields($ar, $cheque->profile),
                        ['account_id' => $this->ledgers->require(self::BOUNCE_FEE_LEDGER), 'credit' => $fee],
                    ],
                    'source_type' => Cheque::class,
                    'source_id'   => $cheque->id,
                    'purpose'     => "cheque_bounce_fee_{$n}",
                ])->ref();
            }

            $this->log($cheque, 'Cheque bounced ' . $this->describe($cheque) . ($cheque->bounce_reason ? " — {$cheque->bounce_reason}" : '')
                . ($drafts ? ' · drafts ' . implode(', ', $drafts) : ''));

            app(AccountsNotifications::class)->chequeBounced($cheque);

            return $drafts;
        });
    }

    /** New cash / release date; a bounced cheque is presented again. */
    public function reschedule(Cheque $cheque, string $date): void
    {
        if (! $cheque->isOpen() && $cheque->status !== 'bounced') {
            throw new RuntimeException('Only an open or bounced cheque can be rescheduled.');
        }

        $from = $cheque->due_date->format('d M');
        $represent = $cheque->status === 'bounced';

        DB::transaction(function () use ($cheque, $date, $from, $represent) {
            $cheque->update(['due_date' => $date] + ($represent ? ['status' => 'pending', 'deposited_at' => null] : []));
            if ($represent) {
                $this->setOrderPayment($cheque, PaymentStatus::PENDING);
            }

            $this->log($cheque, ($represent ? 'Bounced cheque presented again ' : 'Cheque rescheduled ') . $this->describe($cheque)
                . " · {$from} → " . CarbonImmutable::parse($date)->format('d M Y'));
        });
    }

    /** Returned to the party / torn up — no money moves. */
    public function cancel(Cheque $cheque, ?string $reason = null): void
    {
        if (! $cheque->isOpen() && $cheque->status !== 'bounced') {
            throw new RuntimeException('Only an open or bounced cheque can be cancelled.');
        }

        $reason = trim((string) $reason);
        $note = trim(($cheque->note ? "{$cheque->note} · " : '') . ($reason !== '' ? "Cancelled: {$reason}" : ''));

        DB::transaction(function () use ($cheque, $reason, $note) {
            $cheque->update(['status' => 'cancelled', 'note' => $note !== '' ? mb_substr($note, 0, 255) : null]);
            $this->setOrderPayment($cheque, PaymentStatus::FAILED);
            $this->log($cheque, 'Cheque cancelled ' . $this->describe($cheque) . ($reason !== '' ? " — {$reason}" : ''));
        });
    }

    /** The same cheque (number + bank + party, same direction) can't be in the register twice unless the first was bounced / cancelled. */
    protected function assertNotDuplicate(string $direction, string $chequeNo, ?string $bank, ?int $profileId, ?int $exceptId = null): void
    {
        $dupe = Cheque::where('direction', $direction)->where('cheque_no', trim($chequeNo))
            ->where(fn ($q) => $bank ? $q->where('bank_name', $bank) : $q->whereNull('bank_name'))
            ->where('master_profile_id', $profileId)
            ->whereIn('status', [...Cheque::OPEN, 'cleared'])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($dupe) {
            throw new RuntimeException('Cheque ' . trim($chequeNo) . ' আগেই রাখা আছে।');
        }
    }

    protected function describe(Cheque $cheque): string
    {
        return $cheque->label() . ' · ' . Bdt::money($cheque->amount) . ($cheque->order_payment_id ? ' · Order #' . ($cheque->orderPayment?->order_id ?? '?') : '');
    }

    protected function log(Cheque $cheque, string $text): void
    {
        $cheque->profile?->logActivity('cheque', $text, ['cheque_id' => $cheque->id]);
    }
}
