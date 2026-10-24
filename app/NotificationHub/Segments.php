<?php

namespace App\NotificationHub;

use App\Models\CrmLead;
use App\Models\Customer;
use App\Models\MasterProfile;
use App\Services\Profiles\ProfileBalances;
use App\Support\Bdt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who a campaign goes to. Each segment turns its filters into a list of
 * Recipients (one per person, even when they are customer and supplier).
 */
class Segments
{
    public const SEGMENTS = [
        'customers' => ['label' => 'All customers', 'group' => 'Customers'],
        'customer_group' => ['label' => 'A customer group', 'group' => 'Customers'],
        'customers_due' => ['label' => 'Customers who owe money', 'group' => 'Customers'],
        'customers_recent' => ['label' => 'Ordered in the last N days', 'group' => 'Customers'],
        'customers_lapsed' => ['label' => 'No order in the last N days (win-back)', 'group' => 'Customers'],
        'leads' => ['label' => 'Open leads (CRM)', 'group' => 'CRM'],
        'suppliers' => ['label' => 'Suppliers', 'group' => 'Others'],
        'employees' => ['label' => 'Employees', 'group' => 'Others'],
        'owners' => ['label' => 'Owners', 'group' => 'Others'],
        'numbers' => ['label' => 'Pasted phone numbers / emails', 'group' => 'Others'],
    ];

    public function __construct(protected ProfileBalances $balances) {}

    /** @return Collection<int, Recipient> */
    public function recipients(string $segment, array $filters = []): Collection
    {
        $list = match ($segment) {
            'customers' => $this->customers(Customer::query()),
            'customer_group' => $this->customers(Customer::query()->where('customer_group_id', (int) ($filters['group_id'] ?? 0))),
            'customers_recent' => $this->customers(Customer::query()->whereIn('id', $this->orderedSince((int) ($filters['days'] ?? 30)))),
            'customers_lapsed' => $this->customers(Customer::query()
                ->whereIn('id', DB::table('orders')->whereNotNull('customer_id')->select('customer_id'))
                ->whereNotIn('id', $this->orderedSince((int) ($filters['days'] ?? 90)))),
            'customers_due' => $this->customersWithDue((float) ($filters['min_amount'] ?? 1)),
            'leads' => $this->leads(array_filter((array) ($filters['stage_ids'] ?? []))),
            'suppliers', 'employees', 'owners' => MasterProfile::withRole(rtrim($segment, 's'))->with('user')
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
                ->get()->map(fn ($p) => Recipient::fromProfile($p)),
            'numbers' => $this->pasted((string) ($filters['numbers'] ?? '')),
            default => collect(),
        };

        return $list->filter()->unique(fn (Recipient $r) => $r->key())->values();
    }

    protected function customers($query): Collection
    {
        return $query->with(['masterProfile.user', 'user'])->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->get()->map(fn (Customer $c) => Recipient::fromCustomer($c));
    }

    protected function orderedSince(int $days)
    {
        return DB::table('orders')->whereNotNull('customer_id')->where('created_at', '>=', now()->subDays(max(1, $days)))->select('customer_id');
    }

    protected function customersWithDue(float $min): Collection
    {
        $ids = MasterProfile::withRole('customer')->pluck('id')->all();
        $due = $this->balances->forProfiles($ids)->filter(fn ($b) => (float) $b->receivable >= max(0.5, $min));

        return MasterProfile::with('user')->whereIn('id', $due->keys())->get()->map(function (MasterProfile $p) use ($due) {
            $r = Recipient::fromProfile($p);
            $r->data = ['due' => ltrim(Bdt::money((float) $due[$p->id]->receivable), '৳')];

            return $r;
        });
    }

    protected function leads(array $stageIds): Collection
    {
        return CrmLead::open()->when($stageIds, fn ($q) => $q->whereIn('stage_id', $stageIds))->get()
            ->map(fn (CrmLead $l) => Recipient::fromLead($l));
    }

    /** One number or email per line / comma; "Name, 017…" lines keep the name. */
    protected function pasted(string $text): Collection
    {
        return collect(preg_split('/[\r\n;]+/', $text))->map(fn ($line) => trim($line))->filter()->map(function ($line) {
            $parts = array_map('trim', explode(',', $line));
            $email = collect($parts)->first(fn ($p) => filter_var($p, FILTER_VALIDATE_EMAIL));
            $phone = collect($parts)->first(fn ($p) => preg_match('/\d{6,}/', $p));
            $name = collect($parts)->first(fn ($p) => $p !== $email && $p !== $phone) ?: ($phone ?: $email);

            return $phone || $email ? new Recipient((string) $name, $phone, $email) : null;
        });
    }
}
