<?php

namespace App\Livewire\Admin\NotificationCenter\Concerns;

trait GuardsNotificationCenter
{
    protected function guardView(): void
    {
        abort_unless(auth()->user()?->can('notification_center.view'), 403, 'Unauthorized action.');
    }

    protected function guardManage(): void
    {
        abort_unless(auth()->user()?->can('notification_center.manage'), 403, 'Unauthorized action.');
    }

    protected function guardCampaigns(): void
    {
        abort_unless(auth()->user()?->can('notification_campaign.manage'), 403, 'Unauthorized action.');
    }
}
