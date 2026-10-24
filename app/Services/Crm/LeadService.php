<?php

namespace App\Services\Crm;

use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Models\MasterProfile;
use App\Models\User;
use App\NotificationHub\NotificationHub;
use App\Support\BdPhone;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes a lead goes through here, so the timeline,
 * follow-up dates and notifications stay consistent whichever screen made
 * the change (list, Kanban, lead page).
 */
class LeadService
{
    /** Activity types that count as having talked to the lead. */
    public const CONTACT_TYPES = ['call', 'sms', 'email', 'whatsapp', 'meeting'];

    public function __construct(protected NotificationHub $hub) {}

    public function create(array $data, ?User $by = null): CrmLead
    {
        $by ??= auth()->user();

        $lead = DB::transaction(function () use ($data, $by) {
            $lead = CrmLead::create([
                'stage_id' => $data['stage_id'] ?? CrmStage::entry()?->id,
                'assigned_to' => $data['assigned_to'] ?? $this->autoAssignee()?->id,
                'source' => $data['source'] ?? 'other',
                'priority' => $data['priority'] ?? 'normal',
                'created_by' => $by?->id,
            ] + array_intersect_key($data, array_flip([
                'name', 'phone', 'email', 'company', 'expected_value', 'interest', 'address', 'tags', 'notes',
            ])));

            $lead->log('system', 'Lead created' . ($by ? " by {$by->name}" : '') . ' · source: ' . $lead->sourceLabel());

            $hours = (int) CrmSettings::get('first_follow_up_hours');
            if ($hours > 0) {
                $this->scheduleFollowUp($lead, now()->addHours($hours), 'First contact');
            }

            return $lead;
        });

        DB::afterCommit(fn () => $this->hub->fire('lead.created', [
            'lead' => $lead,
            'assignee' => $lead->assignee,
            'data' => $this->placeholders($lead),
            'related' => $lead,
            'url' => route('admin.crm.leads.show', $lead),
            'dedupe' => "lead:{$lead->id}",
        ]));

        return $lead;
    }

    public function update(CrmLead $lead, array $data): CrmLead
    {
        $oldAssignee = $lead->assigned_to;

        $lead->fill(array_intersect_key($data, array_flip([
            'name', 'phone', 'email', 'company', 'source', 'priority', 'expected_value',
            'interest', 'address', 'tags', 'notes', 'assigned_to',
        ])))->save();

        if ($lead->wasChanged('assigned_to')) {
            $this->assigned($lead, $oldAssignee);
        }

        return $lead;
    }

    public function assign(CrmLead $lead, ?int $userId): void
    {
        $old = $lead->assigned_to;
        $lead->forceFill(['assigned_to' => $userId])->save();

        if ($old !== $userId) {
            $this->assigned($lead, $old);
        }
    }

    protected function assigned(CrmLead $lead, ?int $oldUserId): void
    {
        $lead->load('assignee');
        $lead->log('system', $lead->assignee ? "Assigned to {$lead->assignee->name}" : 'Unassigned', ['meta' => ['from' => $oldUserId, 'to' => $lead->assigned_to]]);

        if ($lead->assignee && $lead->assigned_to !== auth()->id()) {
            $this->hub->fire('lead.assigned', [
                'assignee' => $lead->assignee,
                'data' => $this->placeholders($lead) + ['assigned_by' => auth()->user()?->name ?? 'system'],
                'related' => $lead,
                'url' => route('admin.crm.leads.show', $lead),
            ]);
        }
    }

    public function moveToStage(CrmLead $lead, CrmStage $stage, ?string $lostReason = null): void
    {
        if ($lead->stage_id === $stage->id) {
            return;
        }

        $from = $lead->stage?->name ?? '—';

        DB::transaction(function () use ($lead, $stage, $lostReason, $from) {
            $lead->forceFill([
                'stage_id' => $stage->id,
                'won_at' => $stage->is_won ? ($lead->won_at ?? now()) : null,
                'lost_at' => $stage->is_lost ? now() : null,
                'lost_reason' => $stage->is_lost ? ($lostReason ?: $lead->lost_reason) : null,
            ])->save();

            $lead->log('stage', "{$from} → {$stage->name}" . ($stage->is_lost && $lostReason ? " ({$lostReason})" : ''), ['meta' => ['to' => $stage->id]]);

            // A lost lead has nothing left to follow up.
            if ($stage->is_lost) {
                $lead->activities()->openFollowUps()->update(['done_at' => now(), 'outcome' => 'lost']);
                $lead->refreshNextFollowUp();
            }
        });

        $lead->setRelation('stage', $stage);
    }

    public function logActivity(CrmLead $lead, string $type, ?string $body, ?string $outcome = null): CrmActivity
    {
        $activity = $lead->log($type, $body, ['outcome' => $outcome ?: null]);

        if (in_array($type, self::CONTACT_TYPES, true)) {
            $lead->forceFill(['last_contacted_at' => now()])->saveQuietly();
        }

        return $activity;
    }

    public function scheduleFollowUp(CrmLead $lead, CarbonInterface $dueAt, ?string $note = null): CrmActivity
    {
        $activity = $lead->log('follow_up', $note ?: 'Follow up', ['due_at' => $dueAt]);
        $lead->refreshNextFollowUp();

        return $activity;
    }

    public function completeFollowUp(CrmActivity $activity, ?string $outcome = null): void
    {
        $activity->forceFill(['done_at' => now(), 'outcome' => $outcome ?: $activity->outcome])->save();
        $activity->lead->forceFill(['last_contacted_at' => now()])->saveQuietly();
        $activity->lead->refreshNextFollowUp();
    }

    /** @return array{leads: Collection<int, CrmLead>, profile: ?MasterProfile} */
    public function duplicates(?string $phone, ?int $exceptLeadId = null): array
    {
        $phone = BdPhone::normalize($phone);

        if (! $phone || strlen($phone) < 6) {
            return ['leads' => collect(), 'profile' => null];
        }

        return [
            'leads' => CrmLead::with('stage')->where('phone', $phone)->when($exceptLeadId, fn ($q) => $q->whereKeyNot($exceptLeadId))->latest()->limit(5)->get(),
            'profile' => MasterProfile::where('phone', $phone)->first(),
        ];
    }

    /** Who a new lead goes to under CRM › Settings › Auto-assign. */
    public function autoAssignee(): ?User
    {
        return match (CrmSettings::get('auto_assign')) {
            'fixed' => User::find(CrmSettings::get('assign_user_id')),
            'round_robin' => $this->nextInRotation(),
            default => null,
        };
    }

    protected function nextInRotation(): ?User
    {
        $users = $this->assignableUsers();
        if ($users->isEmpty()) {
            return null;
        }

        $last = (int) CrmSettings::get('round_robin_last');
        $next = $users->first(fn (User $u) => $u->id > $last) ?? $users->first();
        CrmSettings::set('round_robin_last', $next->id);

        return $next;
    }

    /** Staff who can work leads (crm.view or crm.manage), for assign pickers and rotation. */
    public function assignableUsers(): Collection
    {
        return User::query()
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', 'active'))
            ->where(fn ($q) => $q
                ->whereHas('permissions', fn ($p) => $p->whereIn('name', ['crm.view', 'crm.manage']))
                ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['superadmin'])
                    ->orWhereHas('permissions', fn ($p) => $p->whereIn('name', ['crm.view', 'crm.manage']))))
            ->orderBy('id')->get();
    }

    public function placeholders(CrmLead $lead): array
    {
        return [
            'name' => $lead->name,
            'phone' => $lead->phone,
            'code' => $lead->code ?? ('L-' . str_pad((string) $lead->id, 4, '0', STR_PAD_LEFT)),
            'source' => $lead->sourceLabel(),
            'interest' => $lead->interest,
        ];
    }
}
