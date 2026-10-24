<?php

namespace App\Livewire\Admin\NotificationCenter;

use App\Models\NotificationDispatch;
use App\NotificationHub\NotificationHub;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notification Center › Outbox: every message the hub wrote — sent, waiting,
 * failed or skipped (with the reason) — searchable, with retry.
 */
class Outbox extends Component
{
    use Concerns\GuardsNotificationCenter;
    use WithPagination;

    #[Url] public string $status = '';
    #[Url] public string $channel = '';
    #[Url] public string $event = '';
    #[Url] public string $search = '';
    #[Url] public string $campaign = '';

    public ?int $openId = null;

    public function mount(): void
    {
        $this->guardView();
    }

    public function updating($name): void
    {
        if (in_array($name, ['status', 'channel', 'event', 'search', 'campaign'], true)) {
            $this->resetPage();
        }
    }

    public function toggle(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
    }

    public function retry(int $id, NotificationHub $hub): void
    {
        $this->guardManage();
        $d = NotificationDispatch::findOrFail($id);
        $hub->retry($d);
        $d->refresh();

        $this->dispatch('toast', ['type' => $d->status === 'sent' ? 'success' : 'error', 'message' => $d->status === 'sent' ? 'Sent' : 'Still failing: ' . $d->error]);
    }

    public function retryAllFailed(NotificationHub $hub): void
    {
        $this->guardManage();
        $n = NotificationDispatch::where('status', 'failed')->where('created_at', '>=', now()->subDays(3))
            ->update(['status' => 'queued', 'error' => null, 'updated_at' => now()->subMinutes(2)]);

        $hub->flush();
        $this->dispatch('toast', ['type' => 'success', 'message' => "{$n} message(s) re-queued"]);
    }

    public function render()
    {
        $q = NotificationDispatch::query()->with(['rule', 'campaign:id,name']);

        $q->when($this->status !== '', fn ($w) => $w->where('status', $this->status))
            ->when($this->channel !== '', fn ($w) => $w->where('channel', $this->channel))
            ->when($this->event !== '', fn ($w) => $w->where('event_key', $this->event))
            ->when(ctype_digit($this->campaign), fn ($w) => $w->where('campaign_id', (int) $this->campaign))
            ->when($this->search !== '', fn ($w) => $w->where(fn ($s) => $s->where('to', 'like', "%{$this->search}%")
                ->orWhere('recipient_name', 'like', "%{$this->search}%")->orWhere('body', 'like', "%{$this->search}%")));

        return view('livewire.admin.notification-center.outbox', [
            'items' => $q->latest('id')->paginate(30),
            'events' => NotificationDispatch::query()->select('event_key')->distinct()->orderBy('event_key')->pluck('event_key'),
            'canManage' => auth()->user()->can('notification_center.manage'),
        ])->layout('layouts.admin.admin');
    }
}
