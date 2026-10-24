<?php

namespace App\Livewire\Accounts;

use App\Enums\Accounts\AccountType;
use App\Enums\Accounts\JournalEntryStatus;
use App\Models\Account;
use App\Models\SmsGatewayConfig;
use App\Models\User;
use App\Services\Accounts\ExpenseCategories;
use App\Services\Accounts\Vouchers;
use App\Sms\SmsManager;
use App\Support\AccountsAccess;
use App\Support\AccountsSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accounts Panel › Settings — design: Settings.dc.html.
 * General, posting rules, voucher numbering, expense categories & budgets,
 * chart of accounts, users & permissions (role × permission matrix), SMS & reminders.
 */
#[Title('Settings')]
#[Layout('layouts.accounts.app')]
class Settings extends Component
{
    public const SECTIONS = [
        'general'    => ['General', 'Business, financial year, lock date'],
        'posting'    => ['Posting rules', 'Approval, attachment, maker-checker'],
        'numbering'  => ['Voucher numbering', 'RV-, PV-, JV- …'],
        'categories' => ['Expense categories', 'Category ও budget'],
        'ledgers'    => ['Chart of accounts', 'সব ledger, নতুন ledger, active / inactive'],
        'users'      => ['Users & permissions', 'Owner, Accountant, Sales, Inventory'],
        'sms'        => ['SMS & reminders', 'Gateway, templates, alerts'],
    ];

    public const RULE_TEXT = [
        'approval' => ['বড় entry-তে Owner approval', 'এই amount-এর বেশি হলে Owner approve না করলে post হবে না'],
        'attach'   => ['Attachment বাধ্যতামূলক', 'এই amount-এর বেশি হলে bill / slip-এর ছবি ছাড়া post হবে না'],
        'maker'    => ['নিজের entry নিজে post করা যাবে না', 'Maker-checker: অন্য একজন review করবে (Owner বাদে)'],
        'lock'     => ['Lock date-এর আগে কিছু বদলানো যাবে না', 'General-এ দেওয়া lock date অনুযায়ী'],
        'auto'     => ['System entry নিজে থেকে post', 'COD settlement, payroll, depreciation — সব check pass করলে'],
        'edit'     => ['Posted entry edit বন্ধ', 'শুধু Reverse করা যাবে, history থাকবে'],
    ];

    public const ROLES = ['owner' => 'Owner', 'accountant' => 'Accountant', 'sales' => 'Sales', 'inventory' => 'Inventory'];

    public const PERMS = [
        'view'     => 'Dashboard ও list দেখা',
        'create'   => 'Voucher তৈরি (draft)',
        'post'     => 'Voucher post করা',
        'approve'  => 'বড় entry approve করা',
        'reverse'  => 'Posted entry reverse করা',
        'reports'  => 'Reports দেখা ও export',
        'cash'     => 'Cash count ও reconcile',
        'settings' => 'Settings বদলানো',
    ];

    /** Non-system ledgers the code still posts to by number (courier expense, salary, repairs) — kept on like system ones. */
    public const CODE_LEDGERS = ['5120', '5130', '5160'];

    #[Url(as: 'section', except: 'general')]
    public string $section = 'general';

    public array $general = [];
    public array $rules = [];
    public array $numbering = [];
    public bool $resetEachFy = true;
    public array $categories = [];
    public string $newCategory = '';
    public array $newLedger = ['name' => '', 'parent_id' => ''];
    public array $matrix = [];
    public array $sms = [];

    public function mount(): void
    {
        AccountsAccess::authorize('view');
        $this->section = array_key_exists($this->section, self::SECTIONS) ? $this->section : 'general';
        $this->load();
    }

    protected function load(): void
    {
        $this->general = [
            'business_name'   => AccountsSettings::businessName(),
            'bin'             => (string) AccountsSettings::get('bin', ''),
            'fy_start_month'  => (string) AccountsSettings::fyStartMonth(),
            'week_start'      => (string) AccountsSettings::weekStart(),
            'currency_format' => (string) AccountsSettings::get('currency_format', 'lakh'),
            'books_start'     => AccountsSettings::booksStart()?->toDateString() ?? '',
            'lock_date'       => AccountsSettings::lockDate()?->toDateString() ?? '',
            'collection_target' => AccountsSettings::collectionTarget() ?: '',
        ];

        $this->rules = collect(AccountsSettings::RULES)->keys()
            ->mapWithKeys(fn ($k) => [$k => AccountsSettings::rule($k)])->all();

        $this->numbering = AccountsSettings::numbering();
        $this->resetEachFy = (bool) AccountsSettings::get('numbering_reset_fy', true);

        $this->categories = ExpenseCategories::all()
            ->map(fn (Account $a) => ['id' => $a->id, 'name' => $a->name, 'budget' => $a->monthly_budget !== null ? (string) (float) $a->monthly_budget : ''])
            ->values()->all();

        $roles = Role::with('permissions:id,name')->whereIn('name', array_keys(self::ROLES))->get()->keyBy('name');
        $this->matrix = collect(self::PERMS)->keys()->mapWithKeys(fn ($p) => [$p => collect(self::ROLES)->keys()
            ->mapWithKeys(fn ($r) => [$r => (bool) $roles->get($r)?->permissions->contains('name', "accounts.{$p}")])->all()])->all();

        $templates = (array) (AccountsSettings::get('sms_templates') ?: []);
        $this->sms = [
            'gateway'       => AccountsSettings::smsGateway(),
            'mask'          => (string) AccountsSettings::get('sms_mask', ''),
            'kisti'         => (string) ($templates['kisti'] ?? AccountsSettings::DEFAULT_TEMPLATES['kisti']),
            'due'           => (string) ($templates['due'] ?? AccountsSettings::DEFAULT_TEMPLATES['due']),
            'stale_voucher' => AccountsSettings::alert('stale_voucher'),
            'emi_3_days'    => AccountsSettings::alert('emi_3_days'),
            'daily_summary' => AccountsSettings::alert('daily_summary'),
        ];
    }

    public function setSection(string $section): void
    {
        $this->section = array_key_exists($section, self::SECTIONS) ? $section : 'general';
    }

    /** 'system' = the active gateway of Advance › SMS configuration; then each installed gateway; 'log' = don't send. */
    public function gateways(): array
    {
        $configured = SmsGatewayConfig::pluck('driver_key')->all();
        $installed = collect(app(SmsManager::class)->installedGateways())
            ->mapWithKeys(fn ($g) => [$g['key'] => $g['label'] . (in_array($g['key'], $configured, true) ? '' : ' (not configured)')]);

        return ['system' => 'System default (Advance › SMS configuration)', ...$installed->all(), 'log' => 'Off — log only (SMS পাঠাবে না)'];
    }

    public function toggleRule(string $key): void
    {
        if (isset($this->rules[$key])) {
            $this->rules[$key]['on'] = ! $this->rules[$key]['on'];
        }
    }

    public function addCategory(): void
    {
        AccountsAccess::authorize('settings');
        $this->validate(['newCategory' => 'required|string|max:80'], [], ['newCategory' => 'category name']);

        $account = ExpenseCategories::create(trim($this->newCategory));
        $this->categories[] = ['id' => $account->id, 'name' => $account->name, 'budget' => ''];
        $this->newCategory = '';
        $this->dispatch('toast', type: 'success', message: "Category {$account->name} added.");
    }

    /* ---------------- Chart of accounts ---------------- */

    /** Every ledger by type, children under their parent, with its posted balance (opening included). */
    #[Computed]
    public function ledgers(): array
    {
        $sums = DB::table('journal_entry_lines as l')->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
            ->where('je.status', JournalEntryStatus::POSTED->value)->groupBy('l.account_id')
            ->selectRaw('l.account_id, COALESCE(SUM(l.debit), 0) as d, COALESCE(SUM(l.credit), 0) as c')->get()->keyBy('account_id');

        $all = Account::orderBy('code')->get();
        $children = $all->groupBy('parent_id');
        $out = [];

        $add = function (Account $a, int $depth) use (&$add, &$out, $sums, $children) {
            $s = $sums[$a->id] ?? null;
            $out[$a->type->label()][] = [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'depth' => $depth, 'subtype' => $a->subtype,
                'system' => $a->is_system || in_array($a->code, self::CODE_LEDGERS, true), 'active' => (bool) $a->is_active,
                'balance' => round((float) $a->opening_balance + $a->normal_balance->signedDelta((float) ($s->d ?? 0), (float) ($s->c ?? 0)), 2),
            ];
            foreach ($children[$a->id] ?? [] as $child) {
                $add($child, $depth + 1);
            }
        };

        foreach (AccountType::cases() as $type) {
            $out[$type->label()] = [];
            foreach ($all->where('type', $type)->whereNull('parent_id') as $root) {
                $add($root, 0);
            }
        }

        return $out;
    }

    /** A new ledger takes its type, subtype and normal balance from its parent; code = next free after the parent's children. */
    public function addLedger(): void
    {
        AccountsAccess::authorize('settings');
        $this->newLedger['name'] = trim((string) $this->newLedger['name']);
        $this->validate([
            'newLedger.name'      => ['required', 'string', 'max:150',
                Rule::unique('accounts', 'name')->where('parent_id', (int) $this->newLedger['parent_id'])->whereNull('deleted_at')],
            'newLedger.parent_id' => ['required', Rule::exists('accounts', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ], ['newLedger.name.unique' => 'এই parent-এর নিচে এই নামে ledger আগে থেকেই আছে।'], ['newLedger.name' => 'ledger name', 'newLedger.parent_id' => 'parent']);

        $parent = Account::findOrFail($this->newLedger['parent_id']);
        $code = (int) (Account::where('parent_id', $parent->id)->max('code') ?: $parent->code) + 1;
        while (Account::withTrashed()->where('code', (string) $code)->exists()) {
            $code++;
        }

        $account = Account::create([
            'code'           => (string) $code,
            'name'           => $this->newLedger['name'],
            'type'           => $parent->type,
            'subtype'        => $parent->subtype,
            'normal_balance' => $parent->normal_balance,
            'parent_id'      => $parent->id,
            'is_system'      => false,
            'is_active'      => true,
        ]);

        activity('accounts')->causedBy(auth()->user())->performedOn($account)->event('ledger')->log("Ledger {$account->code} · {$account->name} added");
        $this->newLedger = ['name' => '', 'parent_id' => ''];
        unset($this->ledgers);
        $this->dispatch('toast', type: 'success', message: "Ledger {$account->code} · {$account->name} added.");
    }

    /** System ledgers stay on; a ledger with money in it can't be switched off (it would vanish from the pickers). */
    public function toggleLedger(int $id): void
    {
        AccountsAccess::authorize('settings');
        $account = Account::findOrFail($id);

        if ($account->is_system || in_array($account->code, self::CODE_LEDGERS, true)) {
            $this->dispatch('toast', type: 'error', message: 'System ledger বন্ধ করা যাবে না।');

            return;
        }
        if ($account->is_active && abs(app(Vouchers::class)->postedBalance($account->id)) > 0.5) {
            $this->dispatch('toast', type: 'error', message: "{$account->name}-এ balance আছে — আগে শূন্য করুন, তারপর বন্ধ করুন।");

            return;
        }

        $account->update(['is_active' => ! $account->is_active]);
        activity('accounts')->causedBy(auth()->user())->performedOn($account)->event('ledger')->log("Ledger {$account->code} · {$account->name} " . ($account->is_active ? 'activated' : 'deactivated'));
        unset($this->ledgers);
        $this->dispatch('toast', type: 'success', message: "{$account->name} " . ($account->is_active ? 'active' : 'inactive') . '.');
    }

    public function discard(): void
    {
        $this->resetErrorBag();
        $this->load();
        $this->dispatch('toast', type: 'info', message: 'Changes discarded.');
    }

    public function save(): void
    {
        AccountsAccess::authorize('settings');

        $this->validate([
            'general.business_name'   => 'required|string|max:120',
            'general.bin'             => 'nullable|string|max:40',
            'general.fy_start_month'  => 'required|in:1,7',
            'general.week_start'      => 'required|in:0,1,6',
            'general.currency_format' => 'required|in:lakh,international',
            'general.books_start'     => 'nullable|date',
            'general.lock_date'       => 'nullable|date',
            'general.collection_target' => 'nullable|numeric|min:0',
            'rules.*.amount'          => 'nullable|numeric|min:0',
            'numbering.*.prefix'      => 'required|string|max:10',
            'numbering.*.next'        => 'required|integer|min:1',
            'categories.*.budget'     => 'nullable|numeric|min:0',
            'sms.gateway'             => 'required|in:' . implode(',', array_keys($this->gateways())),
            'sms.mask'                => 'nullable|string|max:20',
            'sms.kisti'               => 'required|string|max:480',
            'sms.due'                 => 'required|string|max:480',
        ], [], ['general.business_name' => 'business name', 'numbering.*.prefix' => 'prefix', 'numbering.*.next' => 'next number']);

        $g = $this->general;
        foreach (['business_name', 'bin', 'currency_format', 'books_start', 'lock_date'] as $key) {
            AccountsSettings::set($key, $g[$key] !== '' ? $g[$key] : null);
        }
        AccountsSettings::set('fy_start_month', (int) $g['fy_start_month']);
        AccountsSettings::set('week_start', (int) $g['week_start']);
        AccountsSettings::set('collection_target', (float) ($g['collection_target'] ?: 0));

        AccountsSettings::set('rules', collect($this->rules)->map(fn ($r) => ['on' => (bool) $r['on'], 'amount' => (float) ($r['amount'] ?: 0)])->all());
        AccountsSettings::set('numbering', collect($this->numbering)->map(fn ($n) => ['prefix' => trim($n['prefix']), 'next' => (int) $n['next']])->all());
        AccountsSettings::set('numbering_reset_fy', $this->resetEachFy);

        foreach ($this->categories as $c) {
            Account::whereKey($c['id'])->update(['monthly_budget' => $c['budget'] !== '' ? (float) $c['budget'] : null]);
        }

        $this->savePermissions();

        AccountsSettings::set('sms_driver', $this->sms['gateway']);
        AccountsSettings::set('sms_mask', $this->sms['mask'] ?: null);
        AccountsSettings::set('sms_templates', ['kisti' => $this->sms['kisti'], 'due' => $this->sms['due']]);
        AccountsSettings::set('alerts', [
            'stale_voucher' => (bool) $this->sms['stale_voucher'],
            'emi_3_days'    => (bool) $this->sms['emi_3_days'],
            'daily_summary' => (bool) $this->sms['daily_summary'],
        ]);

        activity('accounts')->causedBy(auth()->user())->event('settings')->log('Accounts settings updated');
        $this->dispatch('toast', type: 'success', message: 'Settings saved.');
    }

    protected function savePermissions(): void
    {
        foreach (self::ROLES as $roleName => $label) {
            $role = Role::findOrCreate($roleName, 'web');
            foreach (self::PERMS as $perm => $text) {
                $name = "accounts.{$perm}";
                ($this->matrix[$perm][$roleName] ?? false) ? $role->givePermissionTo($name) : $role->revokePermissionTo($name);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** "Ariful Rahman, Rokeya Begum (Owner) · Sumon Mia (Accountant) …" */
    #[Computed]
    public function usersLine(): string
    {
        $users = User::role(array_keys(self::ROLES))->with('roles:id,name')->orderBy('name')->get(['id', 'name']);

        return collect(self::ROLES)->map(function ($label, $role) use ($users) {
            $names = $users->filter(fn ($u) => $u->roles->contains('name', $role))->pluck('name');

            return $names->isEmpty() ? null : $names->implode(', ') . " ({$label})";
        })->filter()->implode(' · ') ?: 'এখনো কোনো user এই role-এ নেই। Employee profile থেকে login দিন।';
    }

    public function render()
    {
        return view('livewire.accounts.settings', [
            'globalReceipt' => AccountsSettings::attachmentThreshold(),
        ])->layoutData(['active' => 'settings', 'heading' => 'Settings', 'crumb' => 'Accounts / Settings']);
    }
}
