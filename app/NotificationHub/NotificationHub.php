<?php

namespace App\NotificationHub;

use App\Jobs\DeliverNotificationDispatch;
use App\Mail\HubMail;
use App\Models\CrmLead;
use App\Models\MasterProfile;
use App\Models\NotificationCampaign;
use App\Models\NotificationDispatch;
use App\Models\NotificationOptOut;
use App\Models\NotificationRule;
use App\Models\User;
use App\Notifications\HubNotification;
use App\Push\PushManager;
use App\Sms\SmsManager;
use App\Support\AccountsSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The one door business code uses to notify people:
 *
 *     app(NotificationHub::class)->fire('payment.received', [
 *         'customer' => $profile,
 *         'data'     => ['amount' => …, 'due' => …],
 *         'related'  => $entry,
 *     ]);
 *
 * For every active rule of the event it resolves the audience, renders the
 * texts and writes one dispatch per recipient × channel. Instant dispatches
 * are delivered by a queued job; scheduled ones (quiet hours) and campaign
 * ones by notifications:flush. fire() never throws: a notification must
 * never undo or block the business action that caused it.
 */
class NotificationHub
{
    /** @var array{profiles: array<string, true>, leads: array<string, true>}|null */
    protected ?array $optOuts = null;

    protected ?int $smsToday = null;

    public function __construct(protected RecipientResolver $resolver) {}

    /** @return int how many dispatches were written */
    public function fire(string $eventKey, array $context = []): int
    {
        try {
            if (! HubSettings::enabled() || ! NotificationCatalog::event($eventKey)) {
                return 0;
            }

            $this->resetRunState();
            $count = 0;

            foreach (NotificationCatalog::rulesFor($eventKey) as $rule) {
                if (! $rule->is_active || ! $rule->channelList()) {
                    continue;
                }
                if (isset($context['only_rule']) && (int) $context['only_rule'] !== $rule->id) {
                    continue;
                }
                if (isset($context['when']) && ! ($context['when'])($rule)) {
                    continue;
                }

                $count += $this->fireRule($rule, $context);
            }

            return $count;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    protected function fireRule(NotificationRule $rule, array $context): int
    {
        $count = 0;
        $category = NotificationCatalog::category($rule->event_key);

        foreach ($this->resolver->resolve($rule, $context) as $recipient) {
            $data = ($context['data'] ?? []) + ['name' => $recipient->name];
            $data += ['recipient_name' => $recipient->name, 'phone' => $recipient->phone];

            foreach ($rule->channelList() as $channel) {
                $dispatch = $this->queue($recipient, $channel, $this->texts($rule, $channel, $data), [
                    'rule_id' => $rule->id,
                    'event_key' => $rule->event_key,
                    'audience' => $rule->audience,
                    'url' => $context['url'] ?? null,
                    'related' => $context['related'] ?? null,
                    'created_by' => $context['created_by'] ?? auth()->id(),
                ], $category, isset($context['dedupe']) ? "{$rule->event_key}|{$rule->audience}|{$context['dedupe']}" : null);

                $count += $dispatch ? 1 : 0;
            }
        }

        return $count;
    }

    /** @return array{subject: ?string, body: string} */
    public function texts(NotificationRule|NotificationCampaign $source, string $channel, array $data): array
    {
        $label = $source instanceof NotificationRule ? ($source->event()['label'] ?? $source->event_key) : $source->name;

        return match ($channel) {
            'sms' => ['subject' => null, 'body' => $this->render($source->sms_body, $data)],
            'email' => [
                'subject' => $this->render($source->email_subject ?: $label, $data),
                'body' => $this->render($source->email_body ?: $source->sms_body, $data),
            ],
            default => [
                'subject' => $this->render($source->push_title ?: $label, $data),
                'body' => $this->render($source->push_body ?: $source->sms_body, $data),
            ],
        };
    }

    /** {placeholder} → value; {business} is always available. Unknown placeholders are left as typed. */
    public function render(?string $template, array $data): string
    {
        if ($template === null || $template === '') {
            return '';
        }

        $data += ['business' => AccountsSettings::businessName(), 'date' => now()->format('d M Y')];

        $pairs = collect($data)
            ->filter(fn ($v) => is_scalar($v) || $v === null || $v instanceof \Stringable)
            ->mapWithKeys(fn ($v, $k) => ['{' . $k . '}' => (string) $v])
            ->all();

        return trim(strtr($template, $pairs));
    }

    /**
     * Write one dispatch (rules, campaigns and test sends all come through
     * here). Returns null when the channel is switched off or the dedupe key
     * was already used.
     */
    public function queue(Recipient $recipient, string $channel, array $texts, array $attrs, string $category = 'transactional', ?string $dedupe = null, bool $deliverNow = true): ?NotificationDispatch
    {
        if (! HubSettings::channelOn($channel)) {
            return null;
        }

        $dedupeKey = $dedupe ? sha1("{$dedupe}|{$channel}|{$recipient->key()}") : null;
        if ($dedupeKey && NotificationDispatch::where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        $related = $attrs['related'] ?? null;
        unset($attrs['related']);

        $row = $attrs + [
            'channel' => $channel,
            'recipient_type' => $recipient->model ? $recipient->model::class : null,
            'recipient_id' => $recipient->model?->getKey(),
            'recipient_name' => mb_substr($recipient->name, 0, 190),
            'to' => $recipient->address($channel),
            'subject' => $texts['subject'] ? mb_substr($texts['subject'], 0, 250) : null,
            'body' => $texts['body'],
            'related_type' => $related ? $related::class : null,
            'related_id' => $related?->getKey(),
            'dedupe_key' => $dedupeKey,
        ];

        [$row['status'], $row['error'], $row['scheduled_at']] = $this->decide($recipient, $channel, $texts, $category);

        try {
            $dispatch = NotificationDispatch::create($row);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if ($channel === 'sms' && $dispatch->status !== 'skipped') {
            $this->smsToday = ($this->smsToday ?? 0) + 1;
        }

        if ($deliverNow && $dispatch->status === 'queued') {
            DeliverNotificationDispatch::dispatch($dispatch->id)->afterCommit();
        }

        return $dispatch;
    }

    /** @return array{0: string, 1: ?string, 2: ?CarbonImmutable} status, reason, scheduled_at */
    protected function decide(Recipient $recipient, string $channel, array $texts, string $category): array
    {
        if (! $recipient->address($channel)) {
            return ['skipped', match ($channel) {
                'sms' => 'No phone number',
                'email' => 'No email address',
                default => 'No login account for push / in-app',
            }, null];
        }

        if (trim((string) $texts['body']) === '') {
            return ['skipped', 'Empty message — fill in the template', null];
        }

        if ($this->optedOut($recipient, $channel, $category)) {
            return ['skipped', 'Opted out', null];
        }

        if ($channel === 'sms' && ($cap = HubSettings::smsDailyCap()) && $this->smsSentToday() >= $cap) {
            return ['skipped', "Daily SMS cap ({$cap}) reached", null];
        }

        if ($category !== 'transactional' && in_array($channel, ['sms', 'push'], true) && ($until = HubSettings::quietUntil())) {
            return ['scheduled', null, $until];
        }

        return ['queued', null, null];
    }

    public function optedOut(Recipient $recipient, string $channel, string $category): bool
    {
        $this->optOuts ??= $this->loadOptOuts();

        [$bucket, $id] = match (true) {
            $recipient->model instanceof MasterProfile => ['profiles', $recipient->model->id],
            $recipient->model instanceof CrmLead => ['leads', $recipient->model->id],
            default => [null, null],
        };

        if (! $bucket) {
            return false;
        }

        foreach ([$channel, '*'] as $ch) {
            if (isset($this->optOuts[$bucket]["{$id}|{$ch}|all"])) {
                return true;
            }
            if ($category === 'marketing' && isset($this->optOuts[$bucket]["{$id}|{$ch}|marketing"])) {
                return true;
            }
        }

        return false;
    }

    protected function loadOptOuts(): array
    {
        $out = ['profiles' => [], 'leads' => []];

        foreach (NotificationOptOut::all(['master_profile_id', 'lead_id', 'channel', 'category']) as $o) {
            if ($o->master_profile_id) {
                $out['profiles']["{$o->master_profile_id}|{$o->channel}|{$o->category}"] = true;
            }
            if ($o->lead_id) {
                $out['leads']["{$o->lead_id}|{$o->channel}|{$o->category}"] = true;
            }
        }

        return $out;
    }

    protected function smsSentToday(): int
    {
        return $this->smsToday ??= NotificationDispatch::where('channel', 'sms')
            ->whereIn('status', ['queued', 'sending', 'sent', 'scheduled'])
            ->where('created_at', '>=', today())
            ->count();
    }

    /** Opt-outs and the SMS counter are read once per fire / campaign run, not once per message. */
    public function resetRunState(): void
    {
        $this->optOuts = null;
        $this->smsToday = null;
    }

    // ── Delivery ──────────────────────────────────────────────────────

    /** Send one queued dispatch. Safe to call twice: only the caller that claims the row sends. */
    public function deliver(NotificationDispatch $dispatch): void
    {
        $claimed = NotificationDispatch::whereKey($dispatch->id)->where('status', 'queued')
            ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if (! $claimed) {
            return;
        }

        $dispatch->refresh();

        try {
            [$ok, $provider, $error] = match ($dispatch->channel) {
                'sms' => $this->sendSms($dispatch),
                'email' => $this->sendEmail($dispatch),
                'push' => $this->sendPush($dispatch),
                'database' => $this->sendDatabase($dispatch),
                default => [false, null, "Unknown channel {$dispatch->channel}"],
            };
        } catch (Throwable $e) {
            report($e);
            [$ok, $provider, $error] = [false, null, $e->getMessage()];
        }

        $dispatch->forceFill([
            'status' => $ok ? 'sent' : 'failed',
            'provider' => $provider ? mb_substr($provider, 0, 40) : null,
            'error' => $error ? mb_substr($error, 0, 490) : null,
            'sent_at' => $ok ? now() : null,
        ])->save();

        $this->logOnTimeline($dispatch, $ok);
    }

    /** @return array{0: bool, 1: ?string, 2: ?string} */
    protected function sendSms(NotificationDispatch $d): array
    {
        $response = app(SmsManager::class)->sendVia(HubSettings::smsDriver(), $d->to, $d->body, (string) HubSettings::get('sms_sender_id') ?: null, 'notification');

        return [(bool) $response->success, $response->provider ?? null, $response->success ? null : ($response->errorMessage ?: 'SMS gateway refused the message')];
    }

    protected function sendEmail(NotificationDispatch $d): array
    {
        Mail::to($d->to)->send(new HubMail((string) $d->subject, (string) $d->body, $d->url));

        return [true, (string) config('mail.default'), null];
    }

    protected function sendPush(NotificationDispatch $d): array
    {
        $user = $this->userOf($d);
        if (! $user) {
            return [false, null, 'No login account'];
        }

        $provider = app(PushManager::class)->sendToUser($user, $d->event_key, [
            'push_title' => $d->subject,
            'push_body' => $d->body,
            'url' => $d->url,
        ]);

        return [(bool) $provider, $provider, $provider ? null : 'No active push gateway, or this user has not allowed browser notifications'];
    }

    protected function sendDatabase(NotificationDispatch $d): array
    {
        $user = $this->userOf($d);
        if (! $user) {
            return [false, null, 'No login account'];
        }

        $user->notify(new HubNotification($d->event_key, (string) $d->subject, (string) $d->body, $d->url));

        return [true, 'database', null];
    }

    protected function userOf(NotificationDispatch $d): ?User
    {
        $recipient = $d->recipient;

        return match (true) {
            $recipient instanceof User => $recipient,
            $recipient instanceof MasterProfile => $recipient->user,
            default => null,
        };
    }

    /** Every send to a profile shows on its Accounts timeline; to a lead, on the lead's. */
    protected function logOnTimeline(NotificationDispatch $d, bool $ok): void
    {
        if (! in_array($d->channel, ['sms', 'email'], true)) {
            return;
        }

        $what = ($d->rule?->event()['label'] ?? $d->campaign?->name ?? $d->event_key);
        $text = NotificationCatalog::CHANNEL_LABELS[$d->channel] . " · {$what}" . ($ok ? '' : ' — failed');

        rescue(function () use ($d, $text) {
            $recipient = $d->recipient;

            if ($recipient instanceof MasterProfile) {
                $recipient->logActivity($d->channel === 'sms' ? 'sms' : 'email', $text, ['to' => $d->to, 'text' => mb_substr((string) $d->body, 0, 400), 'dispatch_id' => $d->id]);
            } elseif ($recipient instanceof CrmLead) {
                $recipient->log($d->channel, "Auto {$text}\n" . $d->body, ['meta' => ['dispatch_id' => $d->id, 'auto' => true]]);
            }
        }, null, false);
    }

    /** Failed (or skipped) → queued again and sent now. */
    public function retry(NotificationDispatch $dispatch): void
    {
        if (! in_array($dispatch->status, ['failed', 'skipped'], true)) {
            return;
        }

        $dispatch->forceFill(['status' => 'queued', 'error' => null])->save();
        $this->deliver($dispatch);
    }

    /**
     * The minute-by-minute pump (notifications:flush): scheduled → queued
     * when their time comes, campaigns due to start are expanded, then one
     * batch of queued dispatches is delivered.
     *
     * @return array{released: int, delivered: int}
     */
    public function flush(): array
    {
        $released = NotificationDispatch::where('status', 'scheduled')->where('scheduled_at', '<=', now())
            ->update(['status' => 'queued', 'updated_at' => now()]);

        // A worker that died mid-send leaves rows in "sending"; give them another go.
        NotificationDispatch::where('status', 'sending')->where('updated_at', '<', now()->subMinutes(15))->where('attempts', '<', 3)
            ->update(['status' => 'queued']);

        $campaigns = app(Campaigns::class);
        NotificationCampaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->get()
            ->each(fn (NotificationCampaign $c) => $campaigns->start($c));

        $batch = NotificationDispatch::where('status', 'queued')
            ->where(fn ($q) => $q->whereNotNull('campaign_id')->orWhere('updated_at', '<=', now()->subMinute()))
            ->orderBy('id')->limit(HubSettings::batchSize())->get();

        $batch->each(fn (NotificationDispatch $d) => $this->deliver($d));

        $batch->pluck('campaign_id')->filter()->unique()
            ->each(fn ($id) => $campaigns->refresh(NotificationCampaign::find($id)));

        return ['released' => $released, 'delivered' => $batch->count()];
    }

    /** Recipients of a rule for a context, without sending (Rules › preview / test). */
    public function previewRecipients(NotificationRule $rule, array $context = []): Collection
    {
        return $this->resolver->resolve($rule, $context);
    }
}
