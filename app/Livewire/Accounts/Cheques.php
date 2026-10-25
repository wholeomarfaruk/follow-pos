<?php

namespace App\Livewire\Accounts;

use App\Models\Account;
use App\Models\Cheque;
use App\Models\MasterProfile;
use App\Services\Accounts\Cheques as ChequeService;
use App\Services\Accounts\Vouchers;
use App\Services\Profiles\LedgerAccounts;
use App\Support\AccountsAccess;
use App\Support\Bdt;
use App\Support\BdPhone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Accounts Panel › Cheques — every cheque we received (to deposit) or issued
 * (to be presented), by date. A cheque moves no money until it is cleared;
 * clearing makes the receipt / payment voucher (App\Services\Accounts\Cheques).
 */
#[Title('Cheques')]
#[Layout('layouts.accounts.app')]
class Cheques extends Component
{
    public const DIRS = ['all' => 'All', 'received' => 'পেলাম · Received', 'issued' => 'দিলাম · Issued'];

    public const VIEWS = ['open' => 'Open', 'cleared' => 'Cleared', 'bounced' => 'Bounced', 'cancelled' => 'Cancelled', 'all' => 'All'];

    public const ACTIONS = ['clear' => 'Clear · টাকা এসেছে / গেছে', 'bounce' => 'Bounce', 'reschedule' => 'Reschedule', 'cancel' => 'Cancel cheque', 'edit' => 'Edit cheque'];

    #[Url(except: 'all')]
    public string $dir = 'all';

    #[Url(except: 'open')]
    public string $view = 'open';

    #[Url(except: '')]
    public string $q = '';

    public bool $showNew = false;

    public array $new = [];

    public string $partySearch = '';

    public ?int $actionId = null;

    public ?string $action = null;

    public array $act = [];

    public function mount(): void
    {
        AccountsAccess::authorize('view');

        // Cash & Bank › Reconcile suggests "Clear cheque #…" and lands here with ?clear=id.
        $clear = request()->integer('clear');
        if ($clear && AccountsAccess::can('create') && Cheque::whereKey($clear)->open()->exists()) {
            $this->openAction($clear, 'clear');
        }
    }

    #[On('voucher-saved')]
    #[On('cheque-saved')]
    public function refreshData(): void
    {
        unset($this->all);
    }

    public function setDir(string $d): void
    {
        $this->dir = array_key_exists($d, self::DIRS) ? $d : 'all';
    }

    public function setView(string $v): void
    {
        $this->view = array_key_exists($v, self::VIEWS) ? $v : 'open';
    }

    /* ---------------- list ---------------- */

    /** @return Collection<int, Cheque> */
    #[Computed]
    public function all(): Collection
    {
        return Cheque::with(['profile:id,code,display_name,phone', 'entry:id,voucher_ref,entry_number,status', 'account:id,name', 'orderPayment:id,order_id,status'])
            // Open and bounced ones still need action; cleared / cancelled show for 6 months.
            ->where(fn ($q) => $q->whereIn('status', [...Cheque::OPEN, 'bounced'])->orWhere('updated_at', '>=', now()->subMonths(6)))
            ->orderBy('due_date')->orderBy('id')
            ->get();
    }

    #[Computed]
    public function rows(): Collection
    {
        $q = mb_strtolower(trim($this->q));

        return $this->all
            ->filter(fn (Cheque $c) => $this->dir === 'all' || $c->direction === $this->dir)
            ->filter(fn (Cheque $c) => match ($this->view) {
                'open' => $c->isOpen(),
                'all' => true,
                default => $c->status === $this->view,
            })
            ->filter(fn (Cheque $c) => $q === '' || str_contains(mb_strtolower($c->cheque_no . ' ' . $c->bank_name . ' ' . $c->partyName()), $q))
            ->when($this->view !== 'open', fn ($rows) => $rows->sortByDesc(fn (Cheque $c) => $c->updated_at->timestamp))
            ->values();
    }

    /** [style key, label] for a row's status badge. */
    public function badge(Cheque $c): array
    {
        if (! $c->isOpen()) {
            return [$c->status, Cheque::STATUSES[$c->status] ?? $c->status];
        }

        $d = (int) today()->diffInDays($c->due_date, false);
        $prefix = $c->status === 'deposited' ? 'Deposited · ' : '';

        return match (true) {
            $d < 0 => ['late', $prefix . ($c->isReceived() ? 'জমা হয়নি · ' : 'Due · ') . abs($d) . 'd ago'],
            $d === 0 => ['soon', $prefix . 'Today'],
            $d === 1 => ['soon', $prefix . 'Tomorrow'],
            $d <= 3 => ['soon', $prefix . "In {$d} days"],
            default => [$c->status === 'deposited' ? 'deposited' : 'pending', $prefix . "In {$d} days"],
        };
    }

    #[Computed]
    public function cards(): array
    {
        $open = $this->all->filter(fn (Cheque $c) => $c->isOpen());
        $in = $open->where('direction', 'received');
        $out = $open->where('direction', 'issued');
        $week = today()->addDays(7);
        $inWeek = $in->filter(fn (Cheque $c) => $c->due_date->lte($week));
        $outSoon = $out->filter(fn (Cheque $c) => $c->due_date->lte(today()->addDays(3)));
        $vouchers = app(Vouchers::class);
        $bank = $outSoon->pluck('account_id')->filter()->unique()->sum(fn ($id) => $vouchers->postedBalance((int) $id));
        $bounced = $this->all->where('status', 'bounced');

        return [
            'in' => [(float) $in->sum('amount'), $in->count() . 'টা cheque · ' . $in->where('status', 'deposited')->count() . 'টা জমা দেওয়া'],
            'week' => [(float) $inWeek->sum('amount'), $inWeek->count() . 'টা cheque ৭ দিনের মধ্যে'],
            'out' => [(float) $out->sum('amount'), $out->count() . 'টা cheque · ৩ দিনে ' . Bdt::money($outSoon->sum('amount'))],
            'bank' => [$bank, $outSoon->isEmpty() ? '৩ দিনে কোনো cheque নেই' : ($bank + 0.01 >= (float) $outSoon->sum('amount') ? 'Bank-এ যথেষ্ট আছে' : 'Bank-এ কম আছে — টাকা রাখুন'), $outSoon->isNotEmpty() && $bank + 0.01 < (float) $outSoon->sum('amount')],
            'bounced' => [$bounced->count(), $bounced->isEmpty() ? 'কোনো bounce নেই' : Bdt::money($bounced->sum('amount')) . ' · ' . $bounced->take(2)->map(fn (Cheque $c) => $c->partyName())->implode(', ')],
        ];
    }

    /* ---------------- new cheque ---------------- */

    public function openNew(string $direction = 'received'): void
    {
        AccountsAccess::authorize('create');
        $this->resetErrorBag();
        $this->partySearch = '';
        $direction = isset(Cheque::DIRECTIONS[$direction]) ? $direction : 'received';
        $bank = collect($this->cashAccounts)->firstWhere('subtype', 'bank') ?? ($this->cashAccounts[0] ?? null);

        $this->new = [
            'direction'   => $direction,
            'profile_id'  => null,
            'party_label' => '',
            'amount'      => null,
            'cheque_no'   => '',
            'bank_name'   => '',
            'branch'      => '',
            'cheque_date' => today()->toDateString(),
            'due_date'    => '',
            'account_id'  => $bank['id'] ?? null,
            'ledger_id'   => $this->defaultLedger($direction, null),
            'note'        => '',
        ];
        $this->showNew = true;
    }

    public function updatedNew($value, $key): void
    {
        if ($key === 'direction') {
            $this->new['ledger_id'] = $this->defaultLedger((string) $value, $this->picked);
        }
    }

    public function pickParty(int $id): void
    {
        $this->new['profile_id'] = $id;
        $this->partySearch = '';
        unset($this->picked);
        $this->new['ledger_id'] = $this->defaultLedger($this->new['direction'], $this->picked);
    }

    public function clearParty(): void
    {
        $this->new['profile_id'] = null;
        unset($this->picked);
    }

    protected function defaultLedger(string $direction, ?MasterProfile $profile): ?int
    {
        $vouchers = app(Vouchers::class);
        $type = $direction === 'issued' ? 'payment' : 'receipt';

        return $vouchers->defaultLedger($type, $profile)
            ?? (app(LedgerAccounts::class)->id($direction === 'issued' ? LedgerAccounts::PAYABLE : LedgerAccounts::RECEIVABLE) ?: null);
    }

    #[Computed]
    public function picked(): ?MasterProfile
    {
        return ! empty($this->new['profile_id']) ? MasterProfile::with('roles')->find($this->new['profile_id']) : null;
    }

    #[Computed]
    public function partyMatches(): array
    {
        $q = trim($this->partySearch);
        if ($q === '') {
            return [];
        }

        $phone = BdPhone::normalize($q);

        return MasterProfile::query()
            ->where(fn ($w) => $w->where('display_name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")
                ->when($phone && strlen($phone) >= 4, fn ($w) => $w->orWhere('phone', 'like', '%' . ltrim($phone, '0') . '%')))
            ->orderBy('display_name')->limit(8)->get(['id', 'code', 'display_name', 'phone'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->display_name, 'meta' => trim($p->code . ' · ' . ($p->phone ?? ''), ' ·')])
            ->all();
    }

    #[Computed]
    public function cashAccounts(): array
    {
        $vouchers = app(Vouchers::class);

        return collect(app(LedgerAccounts::class)->cashAccounts())
            ->map(fn ($a) => $a + ['balance' => $vouchers->postedBalance($a['id'])])
            ->all();
    }

    /** The other side of the clearing voucher: any non-cash ledger. */
    #[Computed]
    public function ledgers(): array
    {
        return Account::query()->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('subtype')->orWhereNotIn('subtype', LedgerAccounts::CASH_SUBTYPES))
            ->orderBy('code')->get(['id', 'code', 'name', 'type'])
            ->groupBy(fn ($a) => ucfirst($a->type->value))
            ->map(fn ($g) => $g->map(fn ($a) => ['id' => $a->id, 'name' => "{$a->code} · {$a->name}"])->values()->all())
            ->all();
    }

    public function saveNew(): void
    {
        AccountsAccess::authorize('create');
        $this->validate([
            'new.direction'   => ['required', Rule::in(array_keys(Cheque::DIRECTIONS))],
            'new.profile_id'  => 'nullable|integer|exists:master_profiles,id',
            'new.party_label' => 'nullable|string|max:120|required_without:new.profile_id',
            'new.amount'      => 'required|numeric|min:1',
            'new.cheque_no'   => 'required|string|max:60',
            'new.bank_name'   => 'nullable|string|max:120',
            'new.branch'      => 'nullable|string|max:120',
            'new.cheque_date' => 'required|date',
            'new.due_date'    => 'nullable|date',
            'new.account_id'  => ['required', 'integer', Rule::in(collect($this->cashAccounts)->pluck('id')->all())],
            'new.ledger_id'   => 'required|integer|exists:accounts,id',
            'new.note'        => 'nullable|string|max:255',
        ], [], [
            'new.party_label' => 'party', 'new.amount' => 'amount', 'new.cheque_no' => 'cheque no.', 'new.cheque_date' => 'cheque date',
            'new.due_date' => 'cash / release date', 'new.account_id' => 'bank account', 'new.ledger_id' => 'ledger',
        ]);

        try {
            $cheque = app(ChequeService::class)->create($this->new);
        } catch (RuntimeException $e) {
            $this->addError('new.amount', $e->getMessage());

            return;
        }

        $this->showNew = false;
        unset($this->all);
        $this->dispatch('toast', type: 'success', message: "Cheque {$cheque->label()} রাখা হলো — clear হলে voucher হবে।");
    }

    /* ---------------- row actions ---------------- */

    public function deposit(int $id): void
    {
        AccountsAccess::authorize('create');
        $this->run(fn () => app(ChequeService::class)->deposit(Cheque::findOrFail($id)), 'Deposited.');
    }

    public function undoClear(int $id): void
    {
        AccountsAccess::authorize('create');
        $this->run(fn () => app(ChequeService::class)->undoClear(Cheque::findOrFail($id)), 'Clear undone — cheque আবার open।');
    }

    public function openAction(int $id, string $action): void
    {
        AccountsAccess::authorize('create');
        abort_unless(isset(self::ACTIONS[$action]), 404);
        $c = Cheque::findOrFail($id);

        $this->resetErrorBag();
        $this->actionId = $c->id;
        $this->action = $action;
        $this->act = match ($action) {
            'clear' => ['date' => today()->toDateString(), 'account_id' => $c->account_id ?? ($this->cashAccounts[0]['id'] ?? null), 'post' => AccountsAccess::can('post')],
            'bounce' => ['date' => today()->toDateString(), 'reason' => '', 'bank_charge' => null, 'fee' => null],
            'reschedule' => ['date' => $c->due_date->isPast() ? today()->toDateString() : $c->due_date->toDateString()],
            'cancel' => ['reason' => ''],
            'edit' => [
                'cheque_no' => $c->cheque_no, 'bank_name' => (string) $c->bank_name, 'amount' => (float) $c->amount,
                'cheque_date' => $c->cheque_date->toDateString(), 'note' => (string) $c->note,
            ],
        };
    }

    public function closeAction(): void
    {
        $this->actionId = null;
        $this->action = null;
    }

    #[Computed]
    public function acting(): ?Cheque
    {
        return $this->actionId ? Cheque::with('profile:id,display_name')->find($this->actionId) : null;
    }

    public function saveAction(): void
    {
        AccountsAccess::authorize('create');
        $c = $this->acting;
        if (! $c || ! $this->action) {
            $this->closeAction();

            return;
        }

        $this->validate(match ($this->action) {
            'clear' => [
                'act.date' => 'required|date|before_or_equal:today',
                'act.account_id' => ['required', 'integer', Rule::in(collect($this->cashAccounts)->pluck('id')->all())],
            ],
            'bounce' => [
                'act.date' => 'required|date|before_or_equal:today', 'act.reason' => 'nullable|string|max:200',
                'act.bank_charge' => 'nullable|numeric|min:0', 'act.fee' => 'nullable|numeric|min:0',
            ],
            'reschedule' => ['act.date' => 'required|date|after_or_equal:today'],
            'cancel' => ['act.reason' => 'nullable|string|max:200'],
            'edit' => [
                'act.cheque_no' => 'required|string|max:60', 'act.bank_name' => 'nullable|string|max:120',
                'act.amount' => 'required|numeric|min:1', 'act.cheque_date' => 'required|date', 'act.note' => 'nullable|string|max:255',
            ],
        }, [], [
            'act.date' => 'date', 'act.account_id' => 'bank account', 'act.reason' => 'reason', 'act.bank_charge' => 'bank charge', 'act.fee' => 'bounce fee',
            'act.cheque_no' => 'cheque no.', 'act.amount' => 'amount', 'act.cheque_date' => 'cheque date',
        ]);

        $service = app(ChequeService::class);

        try {
            $message = match ($this->action) {
                'clear' => $this->cleared($c, ...$service->clear($c, $this->act['date'], (int) $this->act['account_id'], (bool) ($this->act['post'] ?? false))),
                'bounce' => $this->bounced($c, $service->bounce($c, $this->act['date'], $this->act['reason'] ?? null, (float) ($this->act['bank_charge'] ?? 0), (float) ($this->act['fee'] ?? 0))),
                'reschedule' => tap('Rescheduled to ' . CarbonImmutable::parse($this->act['date'])->format('d M') . '.', fn () => $service->reschedule($c, $this->act['date'])),
                'cancel' => tap('Cheque cancelled.', fn () => $service->cancel($c, $this->act['reason'] ?? null)),
                'edit' => tap('Cheque updated.', fn () => $service->amend($c, $this->act)),
            };
        } catch (RuntimeException $e) {
            $this->addError(match ($this->action) {
                'cancel' => 'act.reason',
                'edit' => 'act.cheque_no',
                default => 'act.date',
            }, $e->getMessage());

            return;
        }

        $this->closeAction();
        unset($this->all);
        $this->dispatch('toast', type: str_contains($message, 'Not posted') ? 'warning' : 'success', message: $message);
    }

    protected function cleared(Cheque $c, ?\App\Models\JournalEntry $entry, ?string $error): string
    {
        return match (true) {
            $entry === null => 'Cheque cleared — order payment paid.',
            $error !== null => "{$entry->ref()} saved as draft. Not posted: {$error}",
            $entry->status->isPending() => "{$entry->ref()} saved — Posting Pending-এ post করলে " . ($c->isReceived() ? 'বাকি' : 'দেনা') . ' কমবে।',
            default => "{$entry->ref()} posted — cheque cleared.",
        };
    }

    /** @param  list<string>  $drafts */
    protected function bounced(Cheque $c, array $drafts): string
    {
        return 'Bounced — ' . ($c->isReceived() ? 'customer' : 'supplier') . '-কে জানানো হবে।'
            . ($drafts ? ' Charge drafts: ' . implode(', ', $drafts) . ' (Posting Pending)।' : '');
    }

    protected function run(callable $do, string $message): void
    {
        try {
            $do();
        } catch (RuntimeException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        unset($this->all);
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function render()
    {
        return view('livewire.accounts.cheques', [
            'money' => fn ($v) => Bdt::money($v),
            'canCreate' => AccountsAccess::can('create'),
            'canPost' => AccountsAccess::can('post'),
            'hasAdmin' => auth()->user()?->hasPanel('admin') ?? false,
        ])->layoutData(['active' => 'cheques', 'heading' => 'Cheques', 'crumb' => 'Accounts / Cheques']);
    }
}
