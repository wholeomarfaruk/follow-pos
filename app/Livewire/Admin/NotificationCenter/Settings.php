<?php

namespace App\Livewire\Admin\NotificationCenter;

use App\Models\MasterProfile;
use App\Models\NotificationOptOut;
use App\NotificationHub\HubSettings;
use App\NotificationHub\NotificationCatalog;
use App\Sms\SmsManager;
use App\Support\BdPhone;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notification Center › Settings: master and channel switches, quiet hours,
 * the SMS gateway / sender ID / daily cap, batch size, and the do-not-send
 * list.
 */
class Settings extends Component
{
    use Concerns\GuardsNotificationCenter;
    use WithPagination;

    public array $s = [];

    public string $optPhone = '';
    public string $optChannel = '*';
    public string $optCategory = 'marketing';

    public function mount(): void
    {
        $this->guardView();
        $this->s = HubSettings::all();
    }

    public function save(): void
    {
        $this->guardManage();

        $this->validate([
            's.quiet_start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            's.quiet_end' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            's.sms_daily_cap' => 'required|integer|min:0|max:1000000',
            's.batch_size' => 'required|integer|min:10|max:1000',
            's.sms_sender_id' => 'nullable|string|max:20',
            's.sms_driver' => 'required|string|max:40',
        ], [], ['s.quiet_start' => 'quiet hours start', 's.quiet_end' => 'quiet hours end', 's.sms_daily_cap' => 'daily SMS cap', 's.batch_size' => 'batch size']);

        foreach (array_keys(HubSettings::DEFAULTS) as $key) {
            $value = $this->s[$key] ?? HubSettings::DEFAULTS[$key];
            HubSettings::set($key, is_bool(HubSettings::DEFAULTS[$key]) ? (bool) $value : (is_int(HubSettings::DEFAULTS[$key]) ? (int) $value : (string) $value));
        }

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Settings saved']);
    }

    public function addOptOut(): void
    {
        $this->guardManage();
        $this->validate(['optPhone' => 'required|string|min:6|max:20'], [], ['optPhone' => 'phone']);

        $phone = BdPhone::normalize($this->optPhone);
        $profile = MasterProfile::where('phone', $phone)->first();
        $lead = \App\Models\CrmLead::where('phone', $phone)->first();

        if (! $profile && ! $lead) {
            $this->addError('optPhone', 'No profile or lead has this phone number.');

            return;
        }

        NotificationOptOut::firstOrCreate([
            'master_profile_id' => $profile?->id,
            'lead_id' => $profile ? null : $lead?->id,
            'channel' => $this->optChannel,
            'category' => $this->optCategory,
        ], ['created_by' => auth()->id()]);

        $profile?->logActivity('note', 'Opted out of ' . ($this->optCategory === 'all' ? 'all' : 'marketing') . ' messages (' . ($this->optChannel === '*' ? 'every channel' : $this->optChannel) . ')');

        $this->optPhone = '';
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Added to the do-not-send list']);
    }

    public function removeOptOut(int $id): void
    {
        $this->guardManage();
        NotificationOptOut::whereKey($id)->delete();
    }

    public function render(SmsManager $sms)
    {
        return view('livewire.admin.notification-center.settings', [
            'gateways' => rescue(fn () => $sms->installedGateways(), [], false),
            'optOuts' => NotificationOptOut::with(['profile:id,code,display_name,phone', 'lead:id,code,name,phone'])->latest('id')->paginate(15),
            'channels' => NotificationCatalog::CHANNEL_LABELS,
            'canManage' => auth()->user()->can('notification_center.manage'),
        ])->layout('layouts.admin.admin');
    }
}
