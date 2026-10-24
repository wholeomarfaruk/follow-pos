<?php

namespace App\Livewire\Admin\Crm\Concerns;

use App\Models\CrmLead;

/**
 * crm.view sees and works the leads assigned to them; crm.manage sees and
 * works every lead (bulk actions, delete, reassign).
 */
trait GuardsCrm
{
    protected function guardView(): void
    {
        abort_unless(auth()->user()?->can('crm.view') || auth()->user()?->can('crm.manage'), 403, 'Unauthorized action.');
    }

    protected function guardManage(): void
    {
        abort_unless($this->canManage(), 403, 'Unauthorized action.');
    }

    protected function canManage(): bool
    {
        return (bool) auth()->user()?->can('crm.manage');
    }

    /** A lead the current user may work on (404 otherwise, so ids can't be probed). */
    protected function findLead(int $id): CrmLead
    {
        return CrmLead::visibleTo(auth()->user())->findOrFail($id);
    }
}
