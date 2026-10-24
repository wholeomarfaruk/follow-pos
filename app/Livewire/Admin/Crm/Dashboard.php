<?php

namespace App\Livewire\Admin\Crm;

use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * CRM › Dashboard: pipeline at a glance — open leads and their weighted
 * value, conversion, follow-up load, where leads come from and who converts.
 */
class Dashboard extends Component
{
    use Concerns\GuardsCrm;

    #[Url] public int $days = 30;

    public function mount(): void
    {
        $this->guardView();
    }

    public function render()
    {
        $user = auth()->user();
        $days = in_array($this->days, [7, 30, 90, 365], true) ? $this->days : 30;
        $since = now()->subDays($days);
        $leads = fn () => CrmLead::query()->visibleTo($user);

        $stages = CrmStage::ordered()->get();
        $open = $leads()->open()->get(['id', 'stage_id', 'expected_value', 'source', 'next_follow_up_at']);

        $created = $leads()->where('created_at', '>=', $since)->count();
        $converted = $leads()->where('converted_at', '>=', $since)->count();
        $lost = $leads()->where('lost_at', '>=', $since)->count();

        $followUps = CrmActivity::openFollowUps()->whereHas('lead', fn ($q) => $q->visibleTo($user)->whereNull('converted_at'));
        $todayEnd = local_time(now())->endOfDay()->utc();
        $todayStart = local_time(now())->startOfDay()->utc();

        $bySource = $leads()->where('created_at', '>=', $since)
            ->selectRaw('source, COUNT(*) as total, SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) as won')
            ->groupBy('source')->orderByDesc('total')->get();

        $byUser = $leads()->where('created_at', '>=', $since)->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) as total, SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) as won')
            ->groupBy('assigned_to')->orderByDesc('won')->limit(8)->get();
        $userNames = User::whereIn('id', $byUser->pluck('assigned_to'))->pluck('name', 'id');

        $lostReasons = $leads()->where('lost_at', '>=', $since)->whereNotNull('lost_reason')
            ->selectRaw('lost_reason, COUNT(*) as n')->groupBy('lost_reason')->orderByDesc('n')->limit(6)->get();

        return view('livewire.admin.crm.dashboard', [
            'days' => $days,
            'stages' => $stages,
            'stageCounts' => $open->groupBy('stage_id')->map->count(),
            'stageValues' => $open->groupBy('stage_id')->map(fn ($g) => $g->sum(fn ($l) => (float) $l->expected_value)),
            'openCount' => $open->count(),
            'pipelineValue' => $open->sum(fn ($l) => (float) $l->expected_value),
            'weightedValue' => $open->sum(fn ($l) => (float) $l->expected_value * (($stages->firstWhere('id', $l->stage_id)?->probability ?? 0) / 100)),
            'created' => $created,
            'converted' => $converted,
            'lost' => $lost,
            'conversionRate' => ($converted + $lost) > 0 ? round($converted / ($converted + $lost) * 100) : null,
            'dueToday' => (clone $followUps)->whereBetween('due_at', [$todayStart, $todayEnd])->count(),
            'overdue' => (clone $followUps)->where('due_at', '<', $todayStart)->count(),
            'noFollowUp' => $leads()->open()->whereNull('next_follow_up_at')->count(),
            'bySource' => $bySource,
            'byUser' => $byUser,
            'userNames' => $userNames,
            'lostReasons' => $lostReasons,
            'recent' => CrmActivity::with(['lead:id,name', 'user:id,name'])
                ->whereHas('lead', fn ($q) => $q->visibleTo($user))
                ->whereNotIn('type', ['system'])->latest('id')->limit(12)->get(),
        ])->layout('layouts.admin.admin');
    }
}
