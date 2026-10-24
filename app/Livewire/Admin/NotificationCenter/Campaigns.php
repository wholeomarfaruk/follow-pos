<?php

namespace App\Livewire\Admin\NotificationCenter;

use App\Models\CrmStage;
use App\Models\CustomerGroup;
use App\Models\NotificationCampaign;
use App\NotificationHub\Campaigns as CampaignService;
use App\NotificationHub\NotificationCatalog;
use App\NotificationHub\NotificationHub;
use App\NotificationHub\Segments;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notification Center › Campaigns: offers and announcements to a segment
 * (all customers, a group, customers who owe, lapsed buyers, open leads,
 * suppliers, employees, owners or a pasted list), now or at a set time.
 */
class Campaigns extends Component
{
    use Concerns\GuardsNotificationCenter;
    use WithPagination;

    public bool $composeModal = false;
    public array $c = [];
    public ?int $reach = null;
    public array $sampleNames = [];

    public function mount(): void
    {
        $this->guardView();
        $this->resetCompose();
    }

    protected function resetCompose(): void
    {
        $this->c = [
            'name' => '',
            'segment' => 'customers',
            'filters' => ['group_id' => '', 'days' => 30, 'min_amount' => 500, 'stage_ids' => [], 'numbers' => ''],
            'channels' => ['sms'],
            'sms_body' => '',
            'email_subject' => '',
            'email_body' => '',
            'push_title' => '',
            'push_body' => '',
            'when' => 'now',
            'scheduled_at' => local_time(now())->addHour()->startOfHour()->format('Y-m-d\TH:i'),
        ];
        $this->reach = null;
        $this->sampleNames = [];
        $this->resetValidation();
    }

    public function openCompose(?int $copyOf = null): void
    {
        $this->guardCampaigns();
        $this->resetCompose();

        if ($copyOf && ($src = NotificationCampaign::find($copyOf))) {
            $this->c = array_merge($this->c, [
                'name' => $src->name . ' (copy)',
                'segment' => $src->segment,
                'filters' => array_merge($this->c['filters'], (array) $src->segment_filters),
                'channels' => (array) $src->channels,
                'sms_body' => (string) $src->sms_body,
                'email_subject' => (string) $src->email_subject,
                'email_body' => (string) $src->email_body,
                'push_title' => (string) $src->push_title,
                'push_body' => (string) $src->push_body,
            ]);
        }

        $this->composeModal = true;
        $this->countReach();
    }

    public function updatedC($value, $key): void
    {
        if (str_starts_with($key, 'segment') || str_starts_with($key, 'filters')) {
            $this->countReach();
        }
    }

    public function countReach(): void
    {
        $list = app(Segments::class)->recipients($this->c['segment'], $this->filters());
        $this->reach = $list->count();
        $this->sampleNames = $list->take(5)->map(fn ($r) => $r->name . ($r->phone ? " ({$r->phone})" : ''))->all();
    }

    protected function filters(): array
    {
        $f = $this->c['filters'];

        return match ($this->c['segment']) {
            'customer_group' => ['group_id' => (int) $f['group_id']],
            'customers_recent', 'customers_lapsed' => ['days' => max(1, (int) $f['days'])],
            'customers_due' => ['min_amount' => max(1, (float) $f['min_amount'])],
            'leads' => ['stage_ids' => array_map('intval', (array) $f['stage_ids'])],
            'numbers' => ['numbers' => (string) $f['numbers']],
            default => [],
        };
    }

    public function launch(CampaignService $campaigns): void
    {
        $this->guardCampaigns();

        $this->validate([
            'c.name' => 'required|string|max:190',
            'c.segment' => ['required', Rule::in(array_keys(Segments::SEGMENTS))],
            'c.channels' => 'required|array|min:1',
            'c.channels.*' => [Rule::in(NotificationCatalog::CHANNELS)],
            'c.sms_body' => 'nullable|string|max:1000|required_without_all:c.email_body,c.push_body',
            'c.email_subject' => 'nullable|string|max:190',
            'c.email_body' => 'nullable|string|max:10000',
            'c.push_title' => 'nullable|string|max:190',
            'c.push_body' => 'nullable|string|max:500',
            'c.when' => 'required|in:now,later',
            'c.scheduled_at' => 'nullable|required_if:c.when,later|date',
            'c.filters.group_id' => 'nullable|required_if:c.segment,customer_group',
            'c.filters.numbers' => 'nullable|required_if:c.segment,numbers|string|max:100000',
        ], [], ['c.name' => 'name', 'c.sms_body' => 'message', 'c.channels' => 'channels', 'c.scheduled_at' => 'send time', 'c.filters.group_id' => 'group', 'c.filters.numbers' => 'numbers']);

        $later = $this->c['when'] === 'later'
            ? Carbon::parse($this->c['scheduled_at'], site_timezone())->utc()
            : null;

        $campaign = NotificationCampaign::create([
            'name' => $this->c['name'],
            'segment' => $this->c['segment'],
            'segment_filters' => $this->filters() ?: null,
            'channels' => array_values($this->c['channels']),
            'sms_body' => $this->c['sms_body'] ?: null,
            'email_subject' => $this->c['email_subject'] ?: null,
            'email_body' => $this->c['email_body'] ?: null,
            'push_title' => $this->c['push_title'] ?: null,
            'push_body' => $this->c['push_body'] ?: null,
            'status' => $later && $later->isFuture() ? 'scheduled' : 'draft',
            'scheduled_at' => $later,
            'created_by' => auth()->id(),
        ]);

        if ($campaign->status === 'draft') {
            $written = $campaigns->start($campaign);
            $msg = "Campaign started — {$written} message(s) queued. They go out in batches every minute.";
        } else {
            $msg = 'Campaign scheduled for ' . local_time($campaign->scheduled_at)->format('d M, h:i A');
        }

        $this->composeModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => $msg]);
    }

    public function cancel(int $id, CampaignService $campaigns): void
    {
        $this->guardCampaigns();
        $campaign = NotificationCampaign::findOrFail($id);

        if (in_array($campaign->status, ['scheduled', 'sending', 'draft'], true)) {
            $campaigns->cancel($campaign);
            $this->dispatch('toast', ['type' => 'success', 'message' => 'Campaign cancelled']);
        }
    }

    public function sendNow(int $id, CampaignService $campaigns): void
    {
        $this->guardCampaigns();
        $campaign = NotificationCampaign::findOrFail($id);

        if ($campaign->status === 'scheduled') {
            $campaign->update(['scheduled_at' => now()]);
            $n = $campaigns->start($campaign);
            $this->dispatch('toast', ['type' => 'success', 'message' => "{$n} message(s) queued"]);
        }
    }

    /** Push one outbox batch now instead of waiting for the next scheduler minute. */
    public function flushNow(NotificationHub $hub): void
    {
        $this->guardCampaigns();
        $r = $hub->flush();
        $this->dispatch('toast', ['type' => 'success', 'message' => "{$r['delivered']} message(s) sent"]);
    }

    public function render(NotificationHub $hub)
    {
        $sample = ['name' => 'Rahim Uddin', 'phone' => '01712345678', 'due' => '1,200'];

        return view('livewire.admin.notification-center.campaigns', [
            'campaigns' => NotificationCampaign::with('creator:id,name')->latest('id')->paginate(15),
            'segments' => Segments::SEGMENTS,
            'groups' => CustomerGroup::orderBy('name')->get(['id', 'name']),
            'stages' => CrmStage::ordered()->where('is_won', false)->where('is_lost', false)->get(),
            'preview' => [
                'sms' => $hub->render($this->c['sms_body'] ?? '', $sample),
                'push_title' => $hub->render(($this->c['push_title'] ?? '') ?: ($this->c['name'] ?? ''), $sample),
                'push_body' => $hub->render(($this->c['push_body'] ?? '') ?: ($this->c['sms_body'] ?? ''), $sample),
            ],
            'canSend' => auth()->user()->can('notification_campaign.manage'),
            'hasActive' => NotificationCampaign::where('status', 'sending')->exists(),
        ])->layout('layouts.admin.admin');
    }
}
