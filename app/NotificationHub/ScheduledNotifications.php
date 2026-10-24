<?php

namespace App\NotificationHub;

use App\Models\CrmActivity;
use App\Models\MasterProfile;
use App\Models\NotificationRule;
use App\Models\ProductVariant;
use App\Models\PromiseToPay;
use App\Models\User;
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
    ];

    public function __construct(protected NotificationHub $hub, protected ProfileBalances $balances) {}

    /** @return array<string, int> "event/audience" => dispatches written */
    public function run(?CarbonImmutable $now = null, bool $force = false): array
    {
        $now ??= CarbonImmutable::now();
        $out = [];

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

        // Someone who promised a date is left alone until that date passes.
        $promised = PromiseToPay::where('status', 'open')->where('due_date', '>=', $now->toDateString())->pluck('master_profile_id')->all();
        $due = $due->except($promised)->sortByDesc(fn ($b) => (float) $b->receivable);

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
}
