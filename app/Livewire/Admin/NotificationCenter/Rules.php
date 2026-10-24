<?php

namespace App\Livewire\Admin\NotificationCenter;

use App\Models\NotificationRule;
use App\NotificationHub\NotificationCatalog;
use App\NotificationHub\NotificationHub;
use App\NotificationHub\Recipient;
use App\Support\AccountsSettings;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Spatie\Permission\Models\Role;

/**
 * Notification Center › Rules: every event × audience with its switch,
 * channels, texts and timing; edit in a modal with a live preview and a
 * test send.
 */
class Rules extends Component
{
    use Concerns\GuardsNotificationCenter;

    public bool $editModal = false;
    public ?int $editingId = null;
    public array $edit = [];

    public string $testTo = '';
    public string $testChannel = 'sms';
    public ?string $testResult = null;

    public function mount(): void
    {
        $this->guardView();
        NotificationCatalog::syncRules();
    }

    public function toggle(int $id): void
    {
        $this->guardManage();
        $rule = NotificationRule::findOrFail($id);

        $rule->is_active = ! $rule->is_active;
        if ($rule->is_active && ! $rule->channelList()) {
            $rule->channels = NotificationCatalog::defaultsFor($rule->event_key, $rule->audience)['channels'] ?: ['database'];
        }
        $rule->save();

        $this->dispatch('toast', ['type' => 'success', 'message' => ($rule->event()['label'] ?? $rule->event_key) . ' → ' . (NotificationCatalog::AUDIENCES[$rule->audience] ?? $rule->audience) . ($rule->is_active ? ' on' : ' off')]);
    }

    public function toggleChannel(int $id, string $channel): void
    {
        $this->guardManage();
        abort_unless(in_array($channel, NotificationCatalog::CHANNELS, true), 422);

        $rule = NotificationRule::findOrFail($id);
        $channels = $rule->channelList();
        $channels = in_array($channel, $channels, true) ? array_values(array_diff($channels, [$channel])) : [...$channels, $channel];
        $rule->update(['channels' => $channels]);
    }

    public function openEdit(int $id): void
    {
        $rule = NotificationRule::findOrFail($id);
        $this->editingId = $rule->id;
        $this->edit = [
            'channels' => $rule->channelList(),
            'sms_body' => (string) $rule->sms_body,
            'email_subject' => (string) $rule->email_subject,
            'email_body' => (string) $rule->email_body,
            'push_title' => (string) $rule->push_title,
            'push_body' => (string) $rule->push_body,
            'send_time' => (string) ($rule->send_time ?: '10:00'),
            'offset_days' => (int) $rule->offset_days,
            'staff_role' => (string) $rule->staff_role,
            'conditions' => (array) $rule->conditions,
        ];

        // Fill any condition the event added after the rule was created.
        foreach ($rule->event()['conditions'] ?? [] as $key => $def) {
            $this->edit['conditions'][$key] ??= $def['default'];
        }

        $this->testResult = null;
        $this->testTo = '';
        $this->testChannel = $this->edit['channels'][0] ?? 'sms';
        $this->resetValidation();
        $this->editModal = true;
    }

    public function save(): void
    {
        $this->guardManage();
        $rule = NotificationRule::findOrFail((int) $this->editingId);

        $this->validate([
            'edit.channels' => 'array',
            'edit.channels.*' => [Rule::in(NotificationCatalog::CHANNELS)],
            'edit.sms_body' => 'nullable|string|max:1000',
            'edit.email_subject' => 'nullable|string|max:190',
            'edit.email_body' => 'nullable|string|max:10000',
            'edit.push_title' => 'nullable|string|max:190',
            'edit.push_body' => 'nullable|string|max:500',
            'edit.send_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'edit.offset_days' => 'nullable|integer|min:-30|max:30',
            'edit.staff_role' => 'nullable|string|max:60',
        ], [], ['edit.send_time' => 'send time']);

        $conditions = collect($rule->event()['conditions'] ?? [])->mapWithKeys(function ($def, $key) {
            $v = $this->edit['conditions'][$key] ?? $def['default'];

            return [$key => match ($def['type']) {
                'number' => max(0, (float) $v),
                'sources' => array_values(array_filter((array) $v)),
                default => $v,
            }];
        })->all();

        $rule->update([
            'channels' => array_values(array_intersect($this->edit['channels'], NotificationCatalog::CHANNELS)),
            'sms_body' => $this->edit['sms_body'] ?: null,
            'email_subject' => $this->edit['email_subject'] ?: null,
            'email_body' => $this->edit['email_body'] ?: null,
            'push_title' => $this->edit['push_title'] ?: null,
            'push_body' => $this->edit['push_body'] ?: null,
            'send_time' => $this->edit['send_time'] ?: '10:00',
            'offset_days' => (int) ($this->edit['offset_days'] ?? 0),
            'staff_role' => $this->edit['staff_role'] ?: null,
            'conditions' => $conditions ?: null,
        ]);

        $this->editModal = false;
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Rule saved']);
    }

    public function resetToDefault(): void
    {
        $this->guardManage();
        $rule = NotificationRule::findOrFail((int) $this->editingId);
        $defaults = NotificationCatalog::defaultsFor($rule->event_key, $rule->audience);
        unset($defaults['is_active']);
        $rule->update($defaults);

        $this->openEdit($rule->id);
        $this->dispatch('toast', ['type' => 'success', 'message' => 'Texts and timing reset to default']);
    }

    public function sendTest(NotificationHub $hub): void
    {
        $this->guardManage();
        $rule = NotificationRule::findOrFail((int) $this->editingId);
        $this->testResult = null;

        $channel = $this->testChannel;
        $user = auth()->user();

        if (in_array($channel, ['sms', 'email'], true)) {
            $this->validate(['testTo' => $channel === 'email' ? 'required|email' : 'required|string|min:6|max:20'], [], ['testTo' => $channel === 'email' ? 'email' : 'phone']);
        }

        $recipient = match ($channel) {
            'sms' => new Recipient($user->name, $this->testTo),
            'email' => new Recipient($user->name, null, $this->testTo),
            default => Recipient::fromUser($user),
        };

        // Test what's in the editor (saved or not), with sample values.
        $draft = new NotificationRule(array_intersect_key($this->edit, array_flip(['sms_body', 'email_subject', 'email_body', 'push_title', 'push_body'])) + ['event_key' => $rule->event_key]);
        $texts = $hub->texts($draft, $channel, $this->sample());
        $texts['subject'] = $texts['subject'] ? '[Test] ' . $texts['subject'] : null;

        $dispatch = $hub->queue($recipient, $channel, $texts, [
            'rule_id' => $rule->id,
            'event_key' => 'test',
            'audience' => $rule->audience,
            'url' => route('admin.notification-center.rules'),
            'created_by' => $user->id,
        ], deliverNow: false);

        if (! $dispatch) {
            $this->testResult = 'error:This channel is switched off in Notification Center › Settings.';

            return;
        }

        if ($dispatch->status === 'queued') {
            $hub->deliver($dispatch);
            $dispatch->refresh();
        }

        $this->testResult = match ($dispatch->status) {
            'sent' => 'ok:Sent' . ($dispatch->provider ? " via {$dispatch->provider}" : '') . '.',
            'skipped' => "error:Skipped — {$dispatch->error}",
            default => 'error:' . ($dispatch->error ?: 'Failed'),
        };
    }

    /** Example values for previews and test sends. */
    public function sample(): array
    {
        return [
            'name' => 'Rahim Uddin', 'recipient_name' => auth()->user()?->name, 'phone' => '01712345678',
            'order_id' => '1024', 'amount' => '2,450', 'due' => '1,200', 'paid' => '1,250', 'total' => '48,500',
            'date' => now()->format('d M Y'), 'source' => 'Website', 'status' => 'Delivered',
            'website_tracking_url' => url('/track?order_id=1024'), 'tracking_url' => 'https://courier.example/track/ABC123',
            'count' => '5', 'overdue' => '2', 'list' => 'Karim Store 12,000, Salma 8,500, Babul 4,200',
            'code' => 'L-0042', 'interest' => '5 pcs katan saree', 'assigned_by' => auth()->user()?->name,
            'business' => AccountsSettings::businessName(),
        ];
    }

    public function render(NotificationHub $hub)
    {
        $rules = NotificationRule::all()->groupBy('event_key');
        $editing = $this->editingId ? NotificationRule::find($this->editingId) : null;
        $sample = $this->sample();

        return view('livewire.admin.notification-center.rules', [
            'groups' => NotificationCatalog::grouped(),
            'rules' => $rules,
            'editing' => $editing,
            'event' => $editing?->event(),
            'preview' => $editing ? [
                'sms' => $hub->render($this->edit['sms_body'] ?? '', $sample),
                'email_subject' => $hub->render(($this->edit['email_subject'] ?? '') ?: ($editing->event()['label'] ?? ''), $sample),
                'email_body' => $hub->render(($this->edit['email_body'] ?? '') ?: ($this->edit['sms_body'] ?? ''), $sample),
                'push_title' => $hub->render(($this->edit['push_title'] ?? '') ?: ($editing->event()['label'] ?? ''), $sample),
                'push_body' => $hub->render(($this->edit['push_body'] ?? '') ?: ($this->edit['sms_body'] ?? ''), $sample),
            ] : [],
            'roles' => Role::orderBy('name')->pluck('name'),
            'canManage' => auth()->user()->can('notification_center.manage'),
            'orderSources' => collect(\App\Enums\Sales\OrderSource::cases())->mapWithKeys(fn ($c) => [$c->value => method_exists($c, 'label') ? $c->label() : ucfirst($c->value)]),
        ])->layout('layouts.admin.admin');
    }
}
