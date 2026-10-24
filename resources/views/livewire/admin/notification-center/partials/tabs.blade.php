@php
    $failedToday = \App\Models\NotificationDispatch::where('status', 'failed')->where('created_at', '>=', today())->count();

    $tabs = [
        'admin.notification-center.overview' => 'Overview',
        'admin.notification-center.rules' => 'Rules',
        'admin.notification-center.campaigns' => 'Campaigns',
        'admin.notification-center.outbox' => ['Outbox', $failedToday ?: null],
        'admin.notification-center.settings' => 'Settings',
    ];
@endphp

@include('livewire.admin.partials.section-tabs', ['tabs' => $tabs])
