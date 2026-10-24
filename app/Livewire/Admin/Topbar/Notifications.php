<?php

namespace App\Livewire\Admin\Topbar;

use Livewire\Component;

class Notifications extends Component
{
    /** "accounts" renders the bordered square bell of the Accounts Panel design. */
    public string $variant = 'default';

    public function markAsRead(string $id): void
    {
        $notification = auth()->user()->notifications()->where('id', $id)->first();
        $notification?->markAsRead();

        // Notification Hub alerts carry a link (the lead, the order…); open it.
        $url = $notification?->data['url'] ?? null;
        if (is_string($url) && str_starts_with($url, url('/'))) {
            $this->redirect($url);
        }
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function render()
    {
        return view('livewire.admin.topbar.notifications', [
            'notifications' => auth()->user()->notifications()->latest()->limit(10)->get(),
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
        ]);
    }
}
