<?php

namespace App\NotificationHub;

use App\Models\CrmLead;
use App\Models\MasterProfile;
use App\Models\NotificationRule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who a rule's audience is for one fire() call. The context carries the
 * parties of the moment (customer / supplier / employee / lead / assignee);
 * owners, staff and accountants are looked up.
 */
class RecipientResolver
{
    /** @return Collection<int, Recipient> */
    public function resolve(NotificationRule $rule, array $context): Collection
    {
        // An explicit list for this audience wins (digests, tests, campaigns).
        if (isset($context['recipients'][$rule->audience])) {
            return $this->normalise(collect($context['recipients'][$rule->audience]));
        }

        $list = match ($rule->audience) {
            'customer', 'supplier', 'employee' => collect([$context[$rule->audience] ?? null]),
            'lead' => collect([$context['lead'] ?? null]),
            'assignee' => collect([$context['assignee'] ?? ($context['lead'] ?? null)?->assignee]),
            'owner' => $this->owners(),
            'staff' => $this->staff($rule, $context),
            'accountant' => $this->accountants(),
            default => collect(),
        };

        return $this->normalise($list);
    }

    /**
     * Users who post or approve Accounts Panel vouchers (as for report
     * delivery). Owners and superadmins are left out: they have audiences
     * of their own.
     *
     * @return Collection<int, User>
     */
    public function accountants(): Collection
    {
        // permission() throws when the permissions aren't migrated yet.
        return rescue(fn () => $this->active(User::permission(['accounts.post', 'accounts.approve']))->get()
            ->reject(fn (User $u) => $u->hasAnyRole(['superadmin', 'owner']))
            ->values(), collect(), false);
    }

    /** @return Collection<int, MasterProfile> */
    public function owners(): Collection
    {
        return MasterProfile::withRole('owner')->with('user')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'))
            ->get();
    }

    /**
     * Staff users for a rule: the role picked on the rule, else whoever holds
     * the event's permission (CRM events: crm.manage), else superadmins/admins.
     *
     * @return Collection<int, User>
     */
    public function staff(NotificationRule $rule, array $context = []): Collection
    {
        if ($rule->staff_role) {
            return $this->withRoles([$rule->staff_role]);
        }

        if (str_starts_with($rule->event_key, 'lead.')) {
            // permission() throws when the permission doesn't exist yet.
            $users = rescue(fn () => $this->active(User::permission('crm.manage'))->get(), collect(), false);
            if ($users->isNotEmpty()) {
                return $users;
            }
        }

        return $this->withRoles(['superadmin', 'admin']);
    }

    /** Users holding any of these roles; unlike User::role(), a missing role is just no one. */
    protected function withRoles(array $roles): Collection
    {
        return $this->active(User::whereHas('roles', fn ($q) => $q->whereIn('name', $roles)))->get();
    }

    protected function active($query)
    {
        return $query->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'active'));
    }

    /** @return Collection<int, Recipient> unique by person */
    protected function normalise(Collection $list): Collection
    {
        return $list->map(fn ($v) => Recipient::from($v))->filter()->unique(fn (Recipient $r) => $r->key())->values();
    }

    /** A lead's assignee as a Recipient (used by CRM screens for previews). */
    public static function assigneeOf(CrmLead $lead): ?Recipient
    {
        return $lead->assignee ? Recipient::fromUser($lead->assignee) : null;
    }
}
