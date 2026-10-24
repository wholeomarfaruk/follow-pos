<?php

namespace App\Livewire\Admin\Crm;

use App\Models\CrmStage;
use App\Models\User;
use App\Services\Crm\CrmSettings;
use App\Services\Crm\LeadService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * CRM › Settings: pipeline stages (order, colour, probability, won / lost)
 * and how new leads are assigned.
 */
class Settings extends Component
{
    public array $stages = [];
    public string $autoAssign = 'none';
    public string $assignUserId = '';
    public int $firstFollowUpHours = 2;
    public bool $convertMovesToWon = true;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('crm_settings.manage'), 403, 'Unauthorized action.');
        $this->loadStages();
        $this->autoAssign = (string) CrmSettings::get('auto_assign');
        $this->assignUserId = (string) CrmSettings::get('assign_user_id');
        $this->firstFollowUpHours = (int) CrmSettings::get('first_follow_up_hours');
        $this->convertMovesToWon = (bool) CrmSettings::get('convert_moves_to_won');
    }

    protected function loadStages(): void
    {
        $this->stages = CrmStage::ordered()->withCount('leads')->get()
            ->map(fn (CrmStage $s) => [
                'id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'probability' => $s->probability,
                'is_won' => $s->is_won, 'is_lost' => $s->is_lost, 'leads' => $s->leads_count,
            ])->all();
    }

    public function addStage(): void
    {
        $this->stages[] = ['id' => null, 'name' => '', 'color' => 'slate', 'probability' => 50, 'is_won' => false, 'is_lost' => false, 'leads' => 0];
    }

    public function removeStage(int $index): void
    {
        $row = $this->stages[$index] ?? null;
        if (! $row) {
            return;
        }

        if ($row['id'] && CrmStage::find($row['id'])?->leads()->withTrashed()->exists()) {
            $this->dispatch('toast', ['type' => 'error', 'message' => 'Move this stage\'s leads first — it still has leads.']);

            return;
        }

        if ($row['id']) {
            CrmStage::whereKey($row['id'])->delete();
        }

        unset($this->stages[$index]);
        $this->stages = array_values($this->stages);
    }

    /** Sortable drop: the new order of row indexes. */
    public function reorder(array $order): void
    {
        $this->stages = collect($order)->map(fn ($i) => $this->stages[(int) $i] ?? null)->filter()->values()->all();
    }

    public function saveStages(): void
    {
        $this->validate([
            'stages' => 'required|array|min:2',
            'stages.*.name' => 'required|string|max:60',
            'stages.*.color' => ['required', Rule::in(array_keys(CrmStage::COLORS))],
            'stages.*.probability' => 'required|integer|min:0|max:100',
        ], [], ['stages.*.name' => 'stage name']);

        $rows = collect($this->stages);
        if (! $rows->contains('is_won', true) || ! $rows->contains('is_lost', true)) {
            $this->addError('stages', 'Keep at least one "won" stage and one "lost" stage.');

            return;
        }
        if ($rows->filter(fn ($r) => $r['is_won'] && $r['is_lost'])->isNotEmpty()) {
            $this->addError('stages', 'A stage can\'t be both won and lost.');

            return;
        }

        foreach ($rows->values() as $i => $r) {
            CrmStage::updateOrCreate(['id' => $r['id']], [
                'name' => $r['name'], 'color' => $r['color'], 'probability' => (int) $r['probability'],
                'is_won' => (bool) $r['is_won'], 'is_lost' => (bool) $r['is_lost'], 'sort' => $i + 1,
            ]);
        }

        $this->loadStages();
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Stages saved']);
    }

    public function saveAssignment(): void
    {
        $this->validate([
            'autoAssign' => 'required|in:none,fixed,round_robin',
            'assignUserId' => 'nullable|required_if:autoAssign,fixed|exists:users,id',
            'firstFollowUpHours' => 'required|integer|min:0|max:720',
        ], [], ['assignUserId' => 'user']);

        CrmSettings::set('auto_assign', $this->autoAssign);
        CrmSettings::set('assign_user_id', $this->assignUserId ? (int) $this->assignUserId : null);
        CrmSettings::set('first_follow_up_hours', $this->firstFollowUpHours);
        CrmSettings::set('convert_moves_to_won', $this->convertMovesToWon);

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Settings saved']);
    }

    public function render(LeadService $leads)
    {
        return view('livewire.admin.crm.settings', [
            'users' => $leads->assignableUsers()->whenEmpty(fn () => User::orderBy('name')->get()),
            'colors' => array_keys(CrmStage::COLORS),
        ])->layout('layouts.admin.admin');
    }
}
