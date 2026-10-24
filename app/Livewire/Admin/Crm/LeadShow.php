<?php

namespace App\Livewire\Admin\Crm;

use App\Actions\Crm\ConvertLeadToCustomer;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Models\NotificationOptOut;
use App\NotificationHub\NotificationHub;
use App\NotificationHub\Recipient;
use App\Services\Crm\LeadService;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Throwable;

/**
 * One lead: details, the timeline (notes, calls, messages, stage moves,
 * system events), follow-up tasks, stage moves and conversion to customer.
 */
class LeadShow extends Component
{
    use Concerns\EditsLeads;
    use Concerns\GuardsCrm;

    public int $leadId;

    // Log an activity
    public string $activityType = 'call';
    public string $activityBody = '';
    public string $activityOutcome = '';
    public bool $sendMessage = false;
    public string $emailSubject = '';

    // Schedule a follow-up
    public string $followUpAt = '';
    public string $followUpNote = '';

    // Complete a follow-up
    public ?int $completingId = null;
    public string $completeOutcome = '';

    public function mount(CrmLead $lead): void
    {
        $this->guardView();
        $this->leadId = $this->findLead($lead->id)->id;
        $this->resetForm();
        $this->followUpAt = local_time(now())->addDay()->setTime(11, 0)->format('Y-m-d\TH:i');
    }

    protected function lead(): CrmLead
    {
        return $this->findLead($this->leadId);
    }

    public function changeStage(int $stageId, LeadService $leads): void
    {
        $lead = $this->lead();
        $stage = CrmStage::findOrFail($stageId);

        if ($stage->is_lost) {
            $this->askLost($lead->id, $stage->id);

            return;
        }

        $leads->moveToStage($lead, $stage);
        $this->dispatch('toast', ['type' => 'success', 'message' => "Moved to {$stage->name}"]);
    }

    public function reassign(string $userId, LeadService $leads): void
    {
        $this->guardManage();
        $leads->assign($this->lead(), $userId === '' ? null : (int) $userId);
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Assignment updated']);
    }

    public function logActivity(LeadService $leads, NotificationHub $hub): void
    {
        $this->validate([
            'activityType' => ['required', Rule::in(CrmActivity::LOGGABLE)],
            'activityBody' => 'required_unless:activityType,call|nullable|string|max:5000',
            'activityOutcome' => ['nullable', Rule::in(array_keys(CrmActivity::OUTCOMES))],
            'emailSubject' => 'nullable|string|max:190',
        ], [], ['activityBody' => 'note']);

        $lead = $this->lead();

        // "Send now": SMS / email go out through the Notification Hub (logged in the Outbox).
        if ($this->sendMessage && in_array($this->activityType, ['sms', 'email'], true)) {
            $dispatch = $hub->queue(Recipient::fromLead($lead), $this->activityType, [
                'subject' => $this->activityType === 'email' ? ($this->emailSubject ?: 'Message from ' . \App\Support\AccountsSettings::businessName()) : null,
                'body' => $hub->render($this->activityBody, $leads->placeholders($lead)),
            ], [
                'event_key' => 'manual',
                'audience' => 'lead',
                'related' => $lead,
                'created_by' => auth()->id(),
            ]);

            if (! $dispatch || $dispatch->status === 'skipped') {
                $this->addError('activityBody', 'Not sent: ' . ($dispatch?->error ?? 'this channel is switched off in Notification Center › Settings.'));

                return;
            }
        }

        $leads->logActivity($lead, $this->activityType, $this->activityBody ?: null, $this->activityOutcome ?: null);

        $this->reset(['activityBody', 'activityOutcome', 'sendMessage', 'emailSubject']);
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Logged']);
    }

    public function scheduleFollowUp(LeadService $leads): void
    {
        $this->validate([
            'followUpAt' => 'required|date',
            'followUpNote' => 'nullable|string|max:500',
        ], [], ['followUpAt' => 'date & time']);

        // The picker sends the admin's local time; store UTC like every other timestamp.
        $at = rescue(fn () => Carbon::parse($this->followUpAt, site_timezone())->utc(), now()->addDay(), false);
        $leads->scheduleFollowUp($this->lead(), $at, $this->followUpNote ?: null);

        $this->followUpNote = '';
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Follow-up scheduled']);
    }

    public function snooze(int $activityId, string $by): void
    {
        $activity = $this->lead()->activities()->openFollowUps()->findOrFail($activityId);
        $activity->update(['due_at' => match ($by) {
            '1h' => now()->addHour(),
            'tomorrow' => now()->addDay()->setTime(11, 0),
            default => now()->addDays(3)->setTime(11, 0),
        }]);
        $activity->lead->refreshNextFollowUp();
    }

    public function openComplete(int $activityId): void
    {
        $this->completingId = $activityId;
        $this->completeOutcome = '';
    }

    public function completeFollowUp(LeadService $leads): void
    {
        $activity = $this->lead()->activities()->openFollowUps()->findOrFail((int) $this->completingId);
        $leads->completeFollowUp($activity, $this->completeOutcome ?: null);
        $this->completingId = null;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Follow-up done']);
    }

    public function convert(ConvertLeadToCustomer $convert): void
    {
        try {
            $customer = $convert->handle($this->lead());
            $this->dispatch('toast', ['type' => 'success', 'message' => "Converted — customer {$customer->customer_code}"]);
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function toggleOptOut(): void
    {
        $lead = $this->lead();
        $row = NotificationOptOut::where('lead_id', $lead->id)->where('channel', '*')->where('category', 'marketing')->first();

        if ($row) {
            $row->delete();
            $lead->log('system', 'Marketing messages allowed again');
        } else {
            NotificationOptOut::create(['lead_id' => $lead->id, 'channel' => '*', 'category' => 'marketing', 'created_by' => auth()->id()]);
            $lead->log('system', 'Opted out of marketing messages');
        }
    }

    public function deleteActivity(int $activityId): void
    {
        $lead = $this->lead();
        $activity = $lead->activities()->whereIn('type', CrmActivity::LOGGABLE)->findOrFail($activityId);

        abort_unless($this->canManage() || $activity->user_id === auth()->id(), 403);

        $activity->delete();
    }

    public function render(LeadService $leads)
    {
        $lead = $this->lead()->load(['stage', 'assignee:id,name', 'creator:id,name', 'profile', 'customer']);

        return view('livewire.admin.crm.lead-show', [
            'lead' => $lead,
            'stages' => CrmStage::ordered()->get(),
            'users' => $leads->assignableUsers(),
            'canManage' => $this->canManage(),
            'timeline' => $lead->activities()->with('user:id,name')->latest('id')->limit(200)->get(),
            'openFollowUps' => $lead->activities()->openFollowUps()->orderBy('due_at')->get(),
            'duplicates' => $leads->duplicates($lead->phone, $lead->id),
            'optedOut' => NotificationOptOut::where('lead_id', $lead->id)->exists(),
        ])->layout('layouts.admin.admin');
    }
}
