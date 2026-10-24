<?php

namespace App\Livewire\Admin\Crm;

use App\Models\CrmActivity;
use App\Services\Crm\LeadService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * CRM › Follow-ups: the day's call list. Overdue first, then today, then the
 * coming week; done in one click (with an outcome) or snoozed.
 */
class FollowUps extends Component
{
    use Concerns\GuardsCrm;
    use WithPagination;

    #[Url] public string $tab = 'today';
    #[Url] public string $who = 'mine';

    public ?int $completingId = null;
    public string $completeOutcome = '';
    public string $completeNote = '';

    public function mount(): void
    {
        $this->guardView();
        if (! $this->canManage()) {
            $this->who = 'mine';
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['tab', 'who'], true)) {
            $this->resetPage();
        }
    }

    protected function base()
    {
        $user = auth()->user();

        return CrmActivity::query()
            ->whereNotNull('due_at')
            ->whereHas('lead', function ($q) use ($user) {
                $q->visibleTo($user)->whereNull('converted_at');
                if ($this->who === 'mine' || ! $this->canManage()) {
                    $q->where(fn ($w) => $w->where('assigned_to', $user->id)->orWhereNull('assigned_to'));
                }
            });
    }

    protected function forTab(string $tab)
    {
        $q = $this->base();
        $today = local_time(now())->startOfDay()->utc();
        $tomorrow = $today->copy()->addDay();

        return match ($tab) {
            'overdue' => $q->whereNull('done_at')->where('due_at', '<', $today),
            'upcoming' => $q->whereNull('done_at')->where('due_at', '>=', $tomorrow)->where('due_at', '<', $tomorrow->copy()->addDays(7)),
            'done' => $q->whereNotNull('done_at')->where('done_at', '>=', $today),
            default => $q->whereNull('done_at')->where('due_at', '>=', $today)->where('due_at', '<', $tomorrow),
        };
    }

    public function openComplete(int $id): void
    {
        $this->completingId = $id;
        $this->completeOutcome = '';
        $this->completeNote = '';
    }

    public function complete(LeadService $leads): void
    {
        $activity = $this->base()->whereNull('done_at')->findOrFail((int) $this->completingId);
        $leads->completeFollowUp($activity, $this->completeOutcome ?: null);

        if (trim($this->completeNote) !== '') {
            $leads->logActivity($activity->lead, 'call', $this->completeNote, $this->completeOutcome ?: null);
        }

        $this->completingId = null;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Done']);
    }

    public function snooze(int $id, string $by): void
    {
        $activity = $this->base()->whereNull('done_at')->findOrFail($id);
        $activity->update(['due_at' => match ($by) {
            '1h' => now()->addHour(),
            'tomorrow' => local_time(now())->addDay()->setTime(11, 0)->utc(),
            default => local_time(now())->addDays(3)->setTime(11, 0)->utc(),
        }]);
        $activity->lead->refreshNextFollowUp();
    }

    public function render()
    {
        return view('livewire.admin.crm.follow-ups', [
            'items' => $this->forTab($this->tab)->with(['lead.stage', 'lead.assignee:id,name'])
                ->orderBy($this->tab === 'done' ? 'done_at' : 'due_at', $this->tab === 'done' ? 'desc' : 'asc')
                ->paginate(30),
            'counts' => collect(['overdue', 'today', 'upcoming', 'done'])->mapWithKeys(fn ($t) => [$t => $this->forTab($t)->count()]),
            'canManage' => $this->canManage(),
        ])->layout('layouts.admin.admin');
    }
}
