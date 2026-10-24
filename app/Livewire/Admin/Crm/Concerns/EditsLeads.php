<?php

namespace App\Livewire\Admin\Crm\Concerns;

use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Services\Crm\LeadService;
use Illuminate\Validation\Rule;

/**
 * The add / edit lead modal and the "mark lost" modal, shared by the Leads
 * page and the lead page (views: crm/partials/lead-form-modal, lost-modal).
 */
trait EditsLeads
{
    public bool $formModal = false;
    public ?int $editingId = null;
    public array $form = [];
    public array $dupes = ['leads' => [], 'profile' => null];

    public bool $lostModal = false;
    public ?int $lostLeadId = null;
    public ?int $lostStageId = null;
    public string $lostReason = '';

    protected function resetForm(): void
    {
        $this->form = [
            'name' => '', 'phone' => '', 'email' => '', 'company' => '',
            'source' => 'facebook', 'priority' => 'normal', 'stage_id' => (string) (CrmStage::entry()?->id ?? ''),
            'assigned_to' => '', 'expected_value' => '', 'interest' => '', 'address' => '', 'notes' => '', 'tags' => '',
        ];
        $this->dupes = ['leads' => [], 'profile' => null];
        $this->editingId = null;
        $this->resetValidation();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        if (! $this->canManage()) {
            $this->form['assigned_to'] = (string) auth()->id();
        }
        $this->formModal = true;
    }

    public function openEdit(int $id): void
    {
        $lead = $this->findLead($id);
        $this->resetForm();
        $this->editingId = $lead->id;
        $this->form = [
            'name' => $lead->name, 'phone' => (string) $lead->phone, 'email' => (string) $lead->email,
            'company' => (string) $lead->company, 'source' => $lead->source, 'priority' => $lead->priority,
            'stage_id' => (string) $lead->stage_id, 'assigned_to' => (string) $lead->assigned_to,
            'expected_value' => (float) $lead->expected_value ? (string) (float) $lead->expected_value : '',
            'interest' => (string) $lead->interest, 'address' => (string) $lead->address,
            'notes' => (string) $lead->notes, 'tags' => implode(', ', (array) $lead->tags),
        ];
        $this->formModal = true;
    }

    public function updatedFormPhone(): void
    {
        $d = app(LeadService::class)->duplicates($this->form['phone'], $this->editingId);
        $this->dupes = [
            'leads' => $d['leads']->map(fn (CrmLead $l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'stage' => $l->stage?->name, 'converted' => (bool) $l->converted_at])->all(),
            'profile' => $d['profile'] ? ['code' => $d['profile']->code, 'name' => $d['profile']->display_name, 'id' => $d['profile']->id] : null,
        ];
    }

    public function save(LeadService $leads): void
    {
        $data = $this->validate([
            'form.name' => 'required|string|max:190',
            'form.phone' => 'nullable|string|max:20|required_without:form.email',
            'form.email' => 'nullable|email|max:190',
            'form.company' => 'nullable|string|max:190',
            'form.source' => ['required', Rule::in(array_keys(CrmLead::SOURCES))],
            'form.priority' => ['required', Rule::in(array_keys(CrmLead::PRIORITIES))],
            'form.stage_id' => 'nullable|exists:crm_stages,id',
            'form.assigned_to' => 'nullable|exists:users,id',
            'form.expected_value' => 'nullable|numeric|min:0',
            'form.interest' => 'nullable|string|max:500',
            'form.address' => 'nullable|string|max:500',
            'form.notes' => 'nullable|string|max:5000',
            'form.tags' => 'nullable|string|max:500',
        ], [], ['form.phone' => 'phone', 'form.email' => 'email', 'form.name' => 'name'])['form'];

        $data['tags'] = collect(explode(',', (string) $data['tags']))->map(fn ($t) => trim($t))->filter()->unique()->values()->all() ?: null;
        $data['expected_value'] = (float) ($data['expected_value'] ?: 0);
        $data['stage_id'] = $data['stage_id'] ?: null;
        $data['assigned_to'] = $data['assigned_to'] ? (int) $data['assigned_to'] : null;

        if (! $this->canManage()) {
            $data['assigned_to'] = auth()->id();
        }

        if ($this->editingId) {
            $lead = $this->findLead($this->editingId);
            $stageId = $data['stage_id'];
            unset($data['stage_id']);
            $leads->update($lead, $data);
            if ($stageId && (int) $stageId !== $lead->stage_id) {
                $leads->moveToStage($lead, CrmStage::findOrFail($stageId));
            }
            $msg = 'Lead updated';
        } else {
            $lead = $leads->create($data);
            $msg = "Lead {$lead->code} added";
        }

        $this->formModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => $msg]);
        $this->afterSave($lead);
    }

    /** Hook for the page (e.g. the Leads page may open the new lead). */
    protected function afterSave(CrmLead $lead): void {}

    public function askLost(int $leadId, ?int $stageId = null): void
    {
        $this->lostLeadId = $leadId;
        $this->lostStageId = $stageId ?? CrmStage::lost()?->id;
        $this->lostReason = '';
        $this->resetValidation('lostReason');
        $this->lostModal = true;
    }

    public function confirmLost(LeadService $leads): void
    {
        $this->validate(['lostReason' => 'required|string|max:190']);
        $lead = $this->findLead((int) $this->lostLeadId);
        $stage = CrmStage::find($this->lostStageId) ?? CrmStage::lost();

        if ($stage) {
            $leads->moveToStage($lead, $stage, $this->lostReason);
        }

        $this->lostModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => "{$lead->name} marked lost"]);
    }
}
