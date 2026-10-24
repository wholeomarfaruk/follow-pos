@php
    $dueFollowUps = \App\Models\CrmActivity::openFollowUps()
        ->where('due_at', '<=', now()->endOfDay())
        ->whereHas('lead', fn ($q) => $q->visibleTo(auth()->user())->whereNull('converted_at'))
        ->count();

    $tabs = [
        'admin.crm.dashboard' => 'Dashboard',
        'admin.crm.leads' => 'Leads',
        'admin.crm.followups' => ['Follow-ups', $dueFollowUps ?: null],
    ];

    if (auth()->user()->can('crm_settings.manage')) {
        $tabs['admin.crm.settings'] = 'Settings';
    }
@endphp

@include('livewire.admin.partials.section-tabs', ['tabs' => $tabs])
