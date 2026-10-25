<?php

namespace App\NotificationHub;

use App\Models\Cheque;
use App\Models\CrmActivity;
use App\Models\Installment;
use App\Models\LoanRepaymentSchedule;
use App\Models\MasterProfile;
use App\Models\NotificationRule;
use App\Models\ProductVariant;
use App\Models\PromiseToPay;
use App\Models\User;
use App\Services\Accounts\PayablesSchedule;
use App\Services\Accounts\Vouchers;
use App\Services\Profiles\ProfileBalances;
use App\Support\Bdt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The "daily" events of the catalog. notifications:daily-scan calls run()
 * every 15 minutes; each active rule is scanned once per day, the first time
 * the clock passes its send_time. Dedupe keys make a second scan harmless
 * anyway (a cleared cache never means a second SMS).
 */
class ScheduledNotifications
{
    public const SCANNERS = [
        'due.reminder' => 'dueReminder',
        'promise.due' => 'promiseDue',
        'birthday' => 'birthday',
        'stock.low' => 'lowStock',
        'lead.followup_due' => 'followUps',
        'payable.due' => 'payablesDue',
        'cheque.received_due' => 'chequesToDeposit',
        'cheque.issued_due' => 'chequesIssued',
        'kisti.due' => 'kistiDue',
        'loan.emi_due' => 'emiDue',
    ];

    public function __construct(protected NotificationHub $hub, protected ProfileBalances $balances) {}

    /** @return array<string, int> "event/audience" => dispatches written */
    public function run(?CarbonImmutable $now = null, bool $force = false): array
    {
        $now ??= CarbonImmutable::now();
        $out = [];

        $this->ensureRules();

        $rules = NotificationRule::whereIn('event_key', array_keys(self::SCANNERS))->where('is_active', true)->get();

        foreach ($rules as $rule) {
            if (! $force && $now->format('H:i') < ($rule->send_time ?: '10:00')) {
                continue;
            }

            $guard = "notification-hub:scan:{$rule->id}:{$now->toDateString()}";
            if (! $force && ! Cache::add($guard, true, $now->endOfDay())) {
                continue;
            }

            try {
                $out["{$rule->event_key}/{$rule->audience}"] = $this->{self::SCANNERS[$rule->event_key]}($rule, $now);
            } catch (Throwable $e) {
                report($e);
                Cache::forget($guard);
            }
        }

        return $out;
    }

    /**
     * Rule rows are made from the catalog when the Notification Center is
     * opened; make any missing ones here too, so a daily event added later
     * runs before anyone visits that page.
     */
    protected function ensureRules(): void
    {
        $keys = array_keys(self::SCANNERS);
        $have = NotificationRule::whereIn('event_key', $keys)->get(['event_key', 'audience'])
            ->map(fn (NotificationRule $r) => "{$r->event_key}|{$r->audience}")->all();

        foreach ($keys as $key) {
            $audiences = array_keys(NotificationCatalog::event($key)['audiences'] ?? []);
            if (array_diff(array_map(fn ($a) => "{$key}|{$a}", $audiences), $have)) {
                NotificationCatalog::syncRules($key);
            }
        }
    }

    protected function fire(NotificationRule $rule, array $context): int
    {
        return $this->hub->fire($rule->event_key, $context + ['only_rule' => $rule->id, 'created_by' => null]);
    }

    protected function money(float $v): string
    {
        return ltrim(Bdt::money($v), '৳');
    }

    // ── due.reminder ─────────────────────────────────────────────────

    protected function dueReminder(NotificationRule $rule, CarbonImmutable $now): int
    {
        $min = max(1, (float) $rule->condition('min_amount', 500));
        $repeat = max(1, (int) $rule->condition('repeat_days', 7));

        $ids = MasterProfile::withRole('customer')->pluck('id')->all();
        $due = $this->balances->forProfiles($ids)->filter(fn ($b) => (float) $b->receivable >= $min);

        // Someone who promised a date is left alone until that date passes,
        // and so is someone whose cheque hasn't been cleared yet.
        $promised = PromiseToPay::where('status', 'open')->where('due_date', '>=', $now->toDateString())->pluck('master_profile_id')->all();
        $chequed = Cheque::open()->where('direction', 'received')->whereNotNull('master_profile_id')->pluck('master_profile_id')->all();
        $due = $due->except([...$promised, ...$chequed])->sortByDesc(fn ($b) => (float) $b->receivable);

        if ($due->isEmpty()) {
            return 0;
        }

        $profiles = MasterProfile::with('user')->whereIn('id', $due->keys())->get()->keyBy('id');

        if ($rule->audience === 'staff') {
            $list = $due->take(5)->map(fn ($b, $id) => ($profiles[$id]->display_name ?? "#{$id}") . ' ' . $this->money((float) $b->receivable))->implode(', ');

            return $this->fire($rule, [
                'data' => ['count' => $due->count(), 'total' => $this->money($due->sum(fn ($b) => (float) $b->receivable)), 'list' => $list],
                'url' => rescue(fn () => route('accounts.receivables'), null, false),
                'dedupe' => 'due-digest:' . $now->toDateString(),
            ]);
        }

        // Every customer gets one reminder per $repeat-day window.
        $window = intdiv($now->startOfDay()->timestamp, 86400 * $repeat);
        $n = 0;

        foreach ($due as $id => $b) {
            if (! isset($profiles[$id])) {
                continue;
            }

            $n += $this->fire($rule, [
                'customer' => $profiles[$id],
                'data' => ['due' => $this->money((float) $b->receivable), 'amount' => $this->money((float) $b->receivable)],
                'related' => $profiles[$id],
                'dedupe' => "due:{$id}:{$window}",
            ]);
        }

        return $n;
    }

    // ── promise.due ──────────────────────────────────────────────────

    protected function promiseDue(NotificationRule $rule, CarbonImmutable $now): int
    {
        // offset_days -1 = a day before the promised date.
        $day = $now->subDays($rule->offset_days)->toDateString();
        $promises = PromiseToPay::with('profile.user')->where('status', 'open')->whereDate('due_date', $day)->get()
            ->filter(fn (PromiseToPay $p) => $p->profile);

        if ($promises->isEmpty()) {
            return 0;
        }

        if ($rule->audience === 'staff') {
            $list = $promises->take(8)->map(fn (PromiseToPay $p) => $p->profile->display_name
                . ($p->kind === 'callback' ? ' (call)' : ($p->amount ? ' ' . $this->money((float) $p->amount) : '')))->implode(', ');

            return $this->fire($rule, [
                'data' => ['count' => $promises->count(), 'list' => $list],
                'url' => rescue(fn () => route('accounts.receivables'), null, false),
                'dedupe' => "promise-digest:{$day}",
            ]);
        }

        // Customers only hear about promises (a callback is a note to ourselves).
        $n = 0;
        foreach ($promises->where('kind', 'promise') as $p) {
            $n += $this->fire($rule, [
                'customer' => $p->profile,
                'data' => ['amount' => $this->money((float) ($p->amount ?: $p->balance_at_promise)), 'date' => $p->due_date->format('d M')],
                'related' => $p,
                'dedupe' => "promise:{$p->id}:{$rule->offset_days}",
            ]);
        }

        return $n;
    }

    // ── birthday ─────────────────────────────────────────────────────

    protected function birthday(NotificationRule $rule, CarbonImmutable $now): int
    {
        $role = $rule->audience === 'employee' ? 'employee' : 'customer';

        $profiles = MasterProfile::withRole($role)->with('user')
            ->whereNotNull('date_of_birth')
            ->whereMonth('date_of_birth', $now->month)->whereDay('date_of_birth', $now->day)
            ->get();

        $n = 0;
        foreach ($profiles as $p) {
            $n += $this->fire($rule, [
                $role => $p,
                'related' => $p,
                'dedupe' => "birthday:{$p->id}:{$now->year}",
            ]);
        }

        return $n;
    }

    // ── stock.low ────────────────────────────────────────────────────

    protected function lowStock(NotificationRule $rule, CarbonImmutable $now): int
    {
        $low = ProductVariant::with('product:id,name')
            ->where('reorder_level', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'reorder_level')
            ->orderByRaw('stock_quantity - reorder_level')
            ->limit(200)->get();

        if ($low->isEmpty()) {
            return 0;
        }

        $list = $low->take(6)->map(fn (ProductVariant $v) => ($v->product?->name ?? $v->sku) . ' (' . rtrim(rtrim(number_format((float) $v->stock_quantity, 2), '0'), '.') . ')')->implode(', ');

        return $this->fire($rule, [
            'data' => ['count' => $low->count(), 'list' => $list . ($low->count() > 6 ? ' …' : '')],
            'url' => rescue(fn () => route('admin.inventory.stock'), null, false),
            'dedupe' => 'low-stock:' . $now->toDateString(),
        ]);
    }

    // ── lead.followup_due ────────────────────────────────────────────

    protected function followUps(NotificationRule $rule, CarbonImmutable $now): int
    {
        $tasks = CrmActivity::openFollowUps()->with('lead:id,name,phone,assigned_to,converted_at,deleted_at')
            ->where('due_at', '<=', $now->endOfDay())
            ->get()
            ->filter(fn (CrmActivity $a) => $a->lead && ! $a->lead->converted_at);

        /** @var Collection<int, Collection<int, CrmActivity>> $byUser */
        $byUser = $tasks->groupBy(fn (CrmActivity $a) => $a->lead->assigned_to ?: $a->user_id)->filter(fn ($g, $uid) => $uid);
        $users = User::whereIn('id', $byUser->keys())->get()->keyBy('id');
        $n = 0;

        foreach ($byUser as $userId => $list) {
            if (! isset($users[$userId])) {
                continue;
            }

            $overdue = $list->filter(fn (CrmActivity $a) => $a->due_at->lt($now->startOfDay()))->count();
            $lines = $list->sortBy('due_at')->take(8)->map(fn (CrmActivity $a) => $a->lead->name . ($a->lead->phone ? " ({$a->lead->phone})" : '') . ($a->body ? ' — ' . mb_substr($a->body, 0, 60) : ''))->implode('; ');

            $n += $this->fire($rule, [
                'assignee' => $users[$userId],
                'data' => ['count' => $list->count(), 'overdue' => $overdue, 'list' => $lines],
                'url' => rescue(fn () => route('admin.crm.followups'), null, false),
                'dedupe' => "followups:{$userId}:" . $now->toDateString(),
            ]);
        }

        return $n;
    }

    // ── payable.due ──────────────────────────────────────────────────

    protected function payablesDue(NotificationRule $rule, CarbonImmutable $now): int
    {
        $days = max(0, (int) $rule->condition('days_before', 1));
        $today = $now->startOfDay();
        // A bill already paid by an issued cheque (waiting to clear) is reminded through cheque.issued_due.
        $chequed = Cheque::open()->where('direction', 'issued')->whereNotNull('meta')->pluck('meta')
            ->flatMap(fn ($m) => (array) ($m['bill_ids'] ?? []))->map(fn ($id) => "bill-{$id}")->all();
        $rows = app(PayablesSchedule::class)->rows(max(1, $days))->reject(fn ($r) => in_array($r['key'], $chequed, true));

        $soon = $rows->filter(fn ($r) => $r['due']->gte($today) && $r['due']->lte($today->addDays($days)));
        if ($soon->isEmpty()) {
            return 0;
        }

        $list = $soon->take(6)->map(fn ($r) => $r['payee'] . ' ' . $this->money((float) $r['amount']) . ' (' . $r['due']->format('d M') . ')')->implode(', ');

        return $this->fire($rule, [
            'data' => [
                'count' => $soon->count(),
                'total' => $this->money((float) $soon->sum('amount')),
                'list' => $list . ($soon->count() > 6 ? ' …' : ''),
                'overdue' => $rows->filter(fn ($r) => $r['due']->lt($today))->count(),
            ],
            'url' => rescue(fn () => route('accounts.payables'), null, false),
            'dedupe' => 'payables-due:' . $now->toDateString(),
        ]);
    }

    // ── cheque.received_due ──────────────────────────────────────────

    /** Received cheques still in hand whose date is N days away (that day only: one reminder per date). */
    protected function chequesToDeposit(NotificationRule $rule, CarbonImmutable $now): int
    {
        $day = $now->startOfDay()->addDays(max(0, (int) $rule->condition('days_before', 1)));

        $cheques = Cheque::with('profile.user')->where('direction', 'received')->where('status', 'pending')
            ->whereDate('due_date', $day->toDateString())->orderByDesc('amount')->get();

        if ($cheques->isEmpty()) {
            return 0;
        }

        if ($rule->audience === 'customer') {
            $n = 0;
            foreach ($cheques->filter(fn (Cheque $c) => $c->profile) as $c) {
                $n += $this->fire($rule, [
                    'customer' => $c->profile,
                    'data' => $this->chequeData($c),
                    'related' => $c,
                    'dedupe' => "cheque-deposit:{$c->id}:{$c->due_date->toDateString()}",
                ]);
            }

            return $n;
        }

        return $this->fire($rule, [
            'data' => $this->chequeDigest($cheques) + ['date' => $day->format('d M')],
            'url' => rescue(fn () => route('accounts.cheques'), null, false),
            'dedupe' => 'cheque-deposit-digest:' . $day->toDateString(),
        ]);
    }

    // ── cheque.issued_due ────────────────────────────────────────────

    /** Cheques we issued, every day from N days before their release date up to that date. */
    protected function chequesIssued(NotificationRule $rule, CarbonImmutable $now): int
    {
        $today = $now->startOfDay();
        $until = $today->addDays(max(0, (int) $rule->condition('days_before', 2)));

        $cheques = Cheque::with('profile')->where('direction', 'issued')->where('status', 'pending')
            ->whereBetween('due_date', [$today->toDateString(), $until->toDateString()])
            ->orderBy('due_date')->get();

        if ($cheques->isEmpty()) {
            return 0;
        }

        $vouchers = app(Vouchers::class);
        $balance = $cheques->pluck('account_id')->filter()->unique()->sum(fn ($id) => $vouchers->postedBalance((int) $id));

        return $this->fire($rule, [
            'data' => $this->chequeDigest($cheques) + ['balance' => $this->money($balance), 'date' => $today->format('d M')],
            'url' => rescue(fn () => route('accounts.cheques', ['dir' => 'issued']), null, false),
            'dedupe' => 'cheque-issued-digest:' . $today->toDateString(),
        ]);
    }

    // ── kisti.due ────────────────────────────────────────────────────

    /** Customer kisti due today, and N days ahead (the "আগামীকাল" reminder). */
    protected function kistiDue(NotificationRule $rule, CarbonImmutable $now): int
    {
        $today = $now->startOfDay();
        $days = max(0, (int) $rule->condition('days_before', 1));
        $dates = array_values(array_unique([$today->toDateString(), $today->addDays($days)->toDateString()]));

        $kisti = Installment::with('plan.profile.user')->where('status', '!=', 'paid')
            ->whereHas('plan', fn ($q) => $q->where('role', 'customer')->where('status', 'active'))
            ->whereIn('due_date', $dates)->orderBy('due_date')->get()
            ->filter(fn (Installment $k) => $k->plan?->profile && (float) $k->adjusted_amount - (float) $k->paid_amount > 0.5);

        if ($kisti->isEmpty()) {
            return 0;
        }

        $left = fn (Installment $k) => round((float) $k->adjusted_amount - (float) $k->paid_amount, 2);

        if ($rule->audience === 'customer') {
            $n = 0;
            foreach ($kisti as $k) {
                $isToday = $k->due_date->isSameDay($today);
                $n += $this->fire($rule, [
                    'customer' => $k->plan->profile,
                    'data' => [
                        'amount' => $this->money($left($k)),
                        'kisti_no' => "{$k->no}/{$k->plan->count}",
                        'date' => $k->due_date->format('d M'),
                        'when' => $isToday ? 'আজ' : ($days === 1 ? 'আগামীকাল' : "{$days} দিন পর"),
                    ],
                    'related' => $k,
                    'dedupe' => "kisti:{$k->id}:{$k->due_date->toDateString()}:" . ($isToday ? 'day' : 'before'),
                ]);
            }

            return $n;
        }

        // Staff: what is due today.
        $due = $kisti->filter(fn (Installment $k) => $k->due_date->isSameDay($today));
        if ($due->isEmpty()) {
            return 0;
        }

        return $this->fire($rule, [
            'data' => [
                'count' => $due->count(),
                'total' => $this->money($due->sum($left)),
                'list' => $due->take(6)->map(fn (Installment $k) => $k->plan->profile->display_name . ' ' . $this->money($left($k)))->implode(', ') . ($due->count() > 6 ? ' …' : ''),
            ],
            'url' => rescue(fn () => route('accounts.loans'), null, false),
            'dedupe' => 'kisti-digest:' . $today->toDateString(),
        ]);
    }

    // ── loan.emi_due ─────────────────────────────────────────────────

    protected function emiDue(NotificationRule $rule, CarbonImmutable $now): int
    {
        $days = max(0, (int) $rule->condition('days_before', 3));
        $day = $now->startOfDay()->addDays($days);

        $due = LoanRepaymentSchedule::with('loan:id,name,lender')
            ->whereIn('status', ['pending', 'overdue'])
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->whereDate('due_date', $day->toDateString())
            ->get()
            ->filter(fn (LoanRepaymentSchedule $s) => $s->payable() - (float) $s->paid_amount > 0.5);

        if ($due->isEmpty()) {
            return 0;
        }

        $left = fn (LoanRepaymentSchedule $s) => round($s->payable() - (float) $s->paid_amount, 2);

        return $this->fire($rule, [
            'data' => [
                'count' => $due->count(),
                'total' => $this->money($due->sum($left)),
                'list' => $due->map(fn (LoanRepaymentSchedule $s) => ($s->loan?->lender ?: $s->loan?->name) . ' ' . $this->money($left($s)))->implode(', '),
                'date' => $day->format('d M'),
                'when' => $days === 0 ? 'আজ' : ($days === 1 ? 'আগামীকাল' : "{$days} দিন পর"),
            ],
            'url' => rescue(fn () => route('accounts.loans'), null, false),
            'dedupe' => 'emi-due:' . $day->toDateString(),
        ]);
    }

    protected function chequeData(Cheque $c): array
    {
        return [
            'amount' => $this->money((float) $c->amount),
            'cheque_no' => $c->cheque_no,
            'bank' => $c->bank_name ?: 'Bank',
            'date' => $c->due_date->format('d M'),
        ];
    }

    /** @param  Collection<int, Cheque>  $cheques */
    protected function chequeDigest(Collection $cheques): array
    {
        $list = $cheques->take(6)->map(fn (Cheque $c) => $c->partyName() . ' ' . $this->money((float) $c->amount) . " (#{$c->cheque_no}, " . $c->due_date->format('d M') . ')')->implode(', ');

        return [
            'count' => $cheques->count(),
            'total' => $this->money((float) $cheques->sum('amount')),
            'list' => $list . ($cheques->count() > 6 ? ' …' : ''),
        ];
    }
}
