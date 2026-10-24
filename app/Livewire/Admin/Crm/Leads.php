<?php

namespace App\Livewire\Admin\Crm;

use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Services\Crm\LeadService;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * CRM › Leads: the list and the Kanban board over the same filters. Add /
 * edit happens in a modal here; the full timeline lives on LeadShow.
 */
class Leads extends Component
{
    use Concerns\EditsLeads;
    use Concerns\GuardsCrm;
    use WithPagination;

    #[Url] public string $view = 'list';
    #[Url] public string $search = '';
    #[Url] public string $stage = '';
    #[Url] public string $source = '';
    #[Url] public string $assignee = '';
    #[Url] public string $priority = '';
    #[Url] public string $status = 'open';

    public array $selected = [];
    public string $bulkStage = '';
    public string $bulkAssignee = '';

    public function mount(): void
    {
        $this->guardView();
        $this->resetForm();

        if (request()->boolean('new')) {
            $this->openCreate();
        }
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'stage', 'source', 'assignee', 'priority', 'status', 'view'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    // ── Pipeline moves (Kanban drag) ─────────────────────────────────

    public function moveLead(int $leadId, int $stageId): void
    {
        $lead = $this->findLead($leadId);
        $stage = CrmStage::findOrFail($stageId);

        if ($stage->is_lost) {
            $this->askLost($lead->id, $stage->id);

            return;
        }

        app(LeadService::class)->moveToStage($lead, $stage);
        $this->dispatch('toast', ['type' => 'success', 'message' => "{$lead->name} → {$stage->name}"]);

        if ($stage->is_won && ! $lead->isConverted()) {
            $this->dispatch('toast', ['type' => 'info', 'message' => 'Won — open the lead to convert it to a customer.']);
        }
    }

    // ── Bulk ─────────────────────────────────────────────────────────

    public function bulkApply(LeadService $leads): void
    {
        $this->guardManage();
        $ids = array_map('intval', $this->selected);

        if (! $ids || (! $this->bulkStage && $this->bulkAssignee === '')) {
            return;
        }

        $stage = $this->bulkStage ? CrmStage::find($this->bulkStage) : null;

        foreach (CrmLead::whereIn('id', $ids)->get() as $lead) {
            if ($stage && ! $stage->is_lost) {
                $leads->moveToStage($lead, $stage);
            }
            if ($this->bulkAssignee !== '') {
                $leads->assign($lead, $this->bulkAssignee === 'none' ? null : (int) $this->bulkAssignee);
            }
        }

        $this->reset(['selected', 'bulkStage', 'bulkAssignee']);
        $this->dispatch('toast', ['type' => 'success', 'message' => count($ids) . ' lead(s) updated']);
    }

    public function bulkDelete(): void
    {
        $this->guardManage();
        $n = CrmLead::whereIn('id', array_map('intval', $this->selected))->get()->each->delete()->count();
        $this->selected = [];
        $this->dispatch('toast', ['type' => 'success', 'message' => "{$n} lead(s) deleted"]);
    }

    public function delete(int $id): void
    {
        $this->guardManage();
        $this->findLead($id)->delete();
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Lead deleted']);
    }

    // ── Data ─────────────────────────────────────────────────────────

    protected function query()
    {
        $q = CrmLead::query()->visibleTo(auth()->user())->with(['stage', 'assignee:id,name']);

        if ($this->search !== '') {
            $s = trim($this->search);
            $digits = preg_replace('/\D+/', '', $s);
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")
                ->orWhere('company', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")
                ->when(strlen($digits) >= 3, fn ($p) => $p->orWhere('phone', 'like', "%{$digits}%")));
        }

        $q->when($this->stage !== '', fn ($w) => $w->where('stage_id', $this->stage))
            ->when($this->source !== '', fn ($w) => $w->where('source', $this->source))
            ->when($this->priority !== '', fn ($w) => $w->where('priority', $this->priority))
            ->when($this->assignee === 'me', fn ($w) => $w->where('assigned_to', auth()->id()))
            ->when($this->assignee === 'none', fn ($w) => $w->whereNull('assigned_to'))
            ->when(ctype_digit($this->assignee), fn ($w) => $w->where('assigned_to', (int) $this->assignee));

        // The board shows every stage as a column, so the open/won/lost filter is list-only.
        if ($this->view !== 'board') {
            match ($this->status) {
                'open' => $q->open(),
                'won' => $q->whereHas('stage', fn ($s) => $s->where('is_won', true)),
                'lost' => $q->whereHas('stage', fn ($s) => $s->where('is_lost', true)),
                'converted' => $q->whereNotNull('converted_at'),
                default => null,
            };
        }

        return $q;
    }

    public function render(LeadService $leads)
    {
        $stages = CrmStage::ordered()->get();
        $board = collect();
        $counts = collect();

        if ($this->view === 'board') {
            $all = $this->query()
                ->orderByRaw('CASE WHEN next_follow_up_at IS NULL THEN 1 ELSE 0 END')->orderBy('next_follow_up_at')->latest('id')
                ->limit(600)->get()->groupBy('stage_id');
            // Closed columns only show the latest few.
            $board = $stages->mapWithKeys(fn (CrmStage $s) => [$s->id => ($all[$s->id] ?? collect())->take($s->isClosed() ? 20 : 150)]);
            $counts = $this->query()->reorder()->selectRaw('stage_id, COUNT(*) as n, SUM(expected_value) as v')->groupBy('stage_id')->get()->keyBy('stage_id');
        }

        return view('livewire.admin.crm.leads', [
            'stages' => $stages,
            'leads' => $this->view === 'board' ? null : $this->query()->latest('id')->paginate(25),
            'board' => $board,
            'counts' => $counts,
            'users' => $leads->assignableUsers(),
            'canManage' => $this->canManage(),
        ])->layout('layouts.admin.admin');
    }
}
