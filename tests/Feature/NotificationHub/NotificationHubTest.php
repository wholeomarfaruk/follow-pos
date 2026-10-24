<?php

namespace Tests\Feature\NotificationHub;

use App\Enums\Profiles\MasterProfileType;
use App\Models\CrmLead;
use App\Models\Customer;
use App\Models\MasterProfile;
use App\Models\NotificationCampaign;
use App\Models\NotificationDispatch;
use App\Models\NotificationOptOut;
use App\Models\NotificationRule;
use App\Models\Order;
use App\Models\PromiseToPay;
use App\Models\User;
use App\NotificationHub\Campaigns;
use App\NotificationHub\HubSettings;
use App\NotificationHub\NotificationCatalog;
use App\NotificationHub\NotificationHub;
use App\NotificationHub\ScheduledNotifications;
use App\Services\Crm\LeadService;
use App\Sms\DTO\SmsResponse;
use App\Sms\SmsManager;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountSeeder;
use Database\Seeders\AssignPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Notification Hub (.scratch/notifications-crm/spec.md §6): rules decide who
 * gets what over which channel; dispatches are skipped with a reason, held
 * for quiet hours, deduplicated, capped, and delivered through the gateways.
 */
class NotificationHubTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    /** @var array<int, array{to: string, text: string}> */
    protected array $sms = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(AssignPermissionSeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(AccountSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Admin']);
        $this->admin->assignRole('superadmin');
        $this->actingAs($this->admin);

        // Every SMS goes to this fake gateway.
        $this->mock(SmsManager::class, function ($mock) {
            $mock->shouldReceive('sendVia')->andReturnUsing(function ($driver, $to, $text) {
                $this->sms[] = ['to' => $to, 'text' => $text];

                return SmsResponse::success('fake');
            });
        });

        HubSettings::set('quiet_enabled', false);
        $this->withoutDefer();
    }

    protected function rule(string $event, string $audience, array $attrs = []): NotificationRule
    {
        NotificationCatalog::syncRules($event);
        $rule = NotificationRule::where('event_key', $event)->where('audience', $audience)->firstOrFail();
        $rule->update($attrs + ['is_active' => true]);

        return $rule->fresh();
    }

    protected function profile(string $name = 'Karim', ?string $phone = '01711000001', string $role = 'customer', array $extra = []): MasterProfile
    {
        $p = MasterProfile::create([
            'uuid' => (string) Str::uuid(), 'type' => MasterProfileType::INDIVIDUAL->value,
            'display_name' => $name, 'phone' => $phone, 'status' => 'active',
        ] + $extra);
        $p->addRole($role);

        return $p->fresh();
    }

    public function test_inactive_rules_send_nothing(): void
    {
        NotificationCatalog::syncRules();
        $n = app(NotificationHub::class)->fire('payment.received', ['customer' => $this->profile(), 'data' => ['amount' => '500']]);

        $this->assertSame(0, $n);
        $this->assertSame(0, NotificationDispatch::count());
    }

    public function test_active_rule_renders_and_sends_sms_and_logs_on_the_profile_timeline(): void
    {
        $this->rule('payment.received', 'customer', ['channels' => ['sms'], 'sms_body' => 'Dear {name}, got {amount} Tk. Due {due}. — {business}']);
        $profile = $this->profile('Karim', '+8801711000001');

        app(NotificationHub::class)->fire('payment.received', ['customer' => $profile, 'data' => ['amount' => '500', 'due' => '1,000']]);

        $d = NotificationDispatch::sole();
        $this->assertSame('sent', $d->status);
        $this->assertSame('01711000001', $d->to);
        $this->assertStringStartsWith('Dear Karim, got 500 Tk. Due 1,000. —', $d->body);
        $this->assertCount(1, $this->sms);
        $this->assertSame('01711000001', $this->sms[0]['to']);
        $this->assertTrue($profile->activities()->where('type', 'sms')->where('text', 'like', '%Payment received%')->exists());
    }

    public function test_missing_address_and_empty_template_are_skipped_with_a_reason(): void
    {
        $this->rule('payment.received', 'customer', ['channels' => ['sms', 'email']]);

        app(NotificationHub::class)->fire('payment.received', ['customer' => $this->profile('No Phone', null), 'data' => ['amount' => '1']]);

        $this->assertEqualsCanonicalizing(
            ['No phone number', 'No email address'],
            NotificationDispatch::where('status', 'skipped')->pluck('error')->all(),
        );
        $this->assertSame([], $this->sms);
    }

    public function test_dedupe_key_sends_only_once(): void
    {
        $this->rule('payment.received', 'customer', ['channels' => ['sms']]);
        $profile = $this->profile();
        $hub = app(NotificationHub::class);

        $hub->fire('payment.received', ['customer' => $profile, 'data' => ['amount' => '5'], 'dedupe' => 'entry:1']);
        $hub->fire('payment.received', ['customer' => $profile, 'data' => ['amount' => '5'], 'dedupe' => 'entry:1']);

        $this->assertSame(1, NotificationDispatch::count());
        $this->assertCount(1, $this->sms);
    }

    public function test_quiet_hours_hold_reminders_until_morning_but_not_transactional(): void
    {
        HubSettings::set('quiet_enabled', true);
        HubSettings::set('quiet_start', '22:00');
        HubSettings::set('quiet_end', '08:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-24 23:15:00'));

        $this->rule('due.reminder', 'customer', ['channels' => ['sms']]);
        $this->rule('payment.received', 'customer', ['channels' => ['sms']]);
        $profile = $this->profile();
        $hub = app(NotificationHub::class);

        $hub->fire('due.reminder', ['customer' => $profile, 'data' => ['due' => '900']]);
        $hub->fire('payment.received', ['customer' => $profile, 'data' => ['amount' => '100']]);

        $reminder = NotificationDispatch::where('event_key', 'due.reminder')->where('audience', 'customer')->sole();
        $this->assertSame('scheduled', $reminder->status);
        $this->assertSame('2026-10-25 08:00:00', $reminder->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('sent', NotificationDispatch::where('event_key', 'payment.received')->sole()->status);
        $this->assertCount(1, $this->sms);

        $this->travelTo(CarbonImmutable::parse('2026-10-25 08:01:00'));
        $hub->flush();

        $this->assertSame('sent', $reminder->fresh()->status);
        $this->assertCount(2, $this->sms);
    }

    public function test_marketing_opt_out_blocks_offers_but_not_receipts(): void
    {
        $this->rule('birthday', 'customer', ['channels' => ['sms']]);
        $this->rule('payment.received', 'customer', ['channels' => ['sms']]);
        $profile = $this->profile();
        NotificationOptOut::create(['master_profile_id' => $profile->id, 'channel' => '*', 'category' => 'marketing']);
        $hub = app(NotificationHub::class);

        $hub->fire('birthday', ['customer' => $profile]);
        $hub->fire('payment.received', ['customer' => $profile, 'data' => ['amount' => '1']]);

        $this->assertSame('Opted out', NotificationDispatch::where('event_key', 'birthday')->sole()->error);
        $this->assertSame('sent', NotificationDispatch::where('event_key', 'payment.received')->sole()->status);
    }

    public function test_daily_sms_cap_skips_over_the_limit(): void
    {
        HubSettings::set('sms_daily_cap', 1);
        $this->rule('payment.received', 'customer', ['channels' => ['sms']]);
        $hub = app(NotificationHub::class);

        $hub->fire('payment.received', ['customer' => $this->profile('A', '01711000001'), 'data' => ['amount' => '1']]);
        $hub->fire('payment.received', ['customer' => $this->profile('B', '01711000002'), 'data' => ['amount' => '1']]);

        $this->assertSame(['sent', 'skipped'], NotificationDispatch::orderBy('id')->pluck('status')->all());
        $this->assertStringContainsString('cap', NotificationDispatch::where('status', 'skipped')->sole()->error);
    }

    public function test_switched_off_channel_writes_nothing(): void
    {
        HubSettings::set('channel_sms', false);
        $this->rule('payment.received', 'customer', ['channels' => ['sms']]);

        app(NotificationHub::class)->fire('payment.received', ['customer' => $this->profile(), 'data' => ['amount' => '1']]);

        $this->assertSame(0, NotificationDispatch::count());
    }

    public function test_owners_get_alerts_and_staff_get_in_app_notifications(): void
    {
        $this->rule('order.big', 'owner', ['channels' => ['sms'], 'conditions' => ['min_total' => 1000]]);
        $this->rule('order.big', 'staff', ['channels' => ['database'], 'conditions' => ['min_total' => 1000]]);
        $this->profile('Owner One', '01811000001', 'owner');

        $order = \Illuminate\Support\Facades\DB::transaction(fn () => Order::create(['source' => 'website', 'status' => 'pending', 'total_amount' => 5000]));

        $this->assertSame('01811000001', $this->sms[0]['to'] ?? null);
        $this->assertStringContainsString("#{$order->id}", $this->sms[0]['text']);
        // order.placed → staff is on by default too, so pick the big-order one.
        $note = $this->admin->notifications()->get()->first(fn ($n) => str_contains($n->data['message'], 'Big order'));
        $this->assertNotNull($note);
        $this->assertStringContainsString("Big order #{$order->id}", $note->data['message']);
        $this->assertSame(route('admin.sales.orders.show', $order->id), $note->data['url']);
    }

    public function test_order_placed_respects_excluded_sources(): void
    {
        $this->rule('order.placed', 'customer', ['channels' => ['sms'], 'conditions' => ['exclude_sources' => ['pos']]]);
        $customer = Customer::create(['customer_code' => 'CUS-1', 'first_name' => 'Rina', 'full_name' => 'Rina', 'phone' => '01911000001', 'status' => 'active']);

        Order::create(['customer_id' => $customer->id, 'source' => 'pos', 'status' => 'pending', 'total_amount' => 100]);
        $this->assertSame([], $this->sms);

        Order::create(['customer_id' => $customer->id, 'source' => 'website', 'status' => 'pending', 'total_amount' => 100]);
        $this->assertSame('01911000001', $this->sms[0]['to'] ?? null);
    }

    public function test_campaign_to_pasted_numbers_goes_out_through_flush(): void
    {
        $campaign = NotificationCampaign::create([
            'name' => 'Eid offer', 'segment' => 'numbers', 'segment_filters' => ['numbers' => "Rahim, 01711000009\n01711000009\n01711000010"],
            'channels' => ['sms'], 'sms_body' => 'Hi {name}, 20% off — {business}', 'status' => 'draft', 'created_by' => $this->admin->id,
        ]);

        $written = app(Campaigns::class)->start($campaign);
        $this->assertSame(2, $written, 'the repeated number is sent once');
        $this->assertSame([], $this->sms, 'campaigns wait for the flush');

        app(NotificationHub::class)->flush();

        $campaign->refresh();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(2, $campaign->sent_count);
        $this->assertStringStartsWith('Hi Rahim, 20% off', $this->sms[0]['text']);
    }

    public function test_daily_scan_reminds_promises_and_follow_ups_once(): void
    {
        $this->rule('promise.due', 'customer', ['channels' => ['sms']]);
        $this->rule('lead.followup_due', 'assignee', ['channels' => ['database']]);
        $profile = $this->profile();
        PromiseToPay::create(['master_profile_id' => $profile->id, 'kind' => 'promise', 'due_date' => today(), 'amount' => 1500, 'status' => 'open']);
        $lead = app(LeadService::class)->create(['name' => 'Lead L', 'phone' => '01611000001', 'assigned_to' => $this->admin->id]);
        app(LeadService::class)->scheduleFollowUp($lead, now()->subHour(), 'Call back');

        $scan = app(ScheduledNotifications::class);
        $scan->run(null, true);
        $scan->run(null, true);

        // Customer SMS once; the staff in-app digest (on by default) once.
        $this->assertSame(1, NotificationDispatch::where('event_key', 'promise.due')->where('audience', 'customer')->count());
        $this->assertSame(1, NotificationDispatch::where('event_key', 'promise.due')->where('audience', 'staff')->count());
        $this->assertStringContainsString('1,500', $this->sms[0]['text']);
        $digest = NotificationDispatch::where('event_key', 'lead.followup_due')->sole();
        $this->assertStringContainsString('Lead L', $digest->body);
    }

    public function test_failed_dispatch_can_be_retried(): void
    {
        $this->rule('payment.received', 'customer', ['channels' => ['sms']]);
        $this->mock(SmsManager::class, function ($mock) {
            $mock->shouldReceive('sendVia')->once()->andReturn(SmsResponse::failure('fake', 'x', 'Gateway down'));
            $mock->shouldReceive('sendVia')->once()->andReturn(SmsResponse::success('fake'));
        });
        $hub = app(NotificationHub::class);

        $hub->fire('payment.received', ['customer' => $this->profile(), 'data' => ['amount' => '1']]);
        $d = NotificationDispatch::sole();
        $this->assertSame('failed', $d->status);
        $this->assertSame('Gateway down', $d->error);

        $hub->retry($d);
        $this->assertSame('sent', $d->fresh()->status);
    }

    public function test_quiet_window_maths(): void
    {
        HubSettings::set('quiet_enabled', true);
        HubSettings::set('quiet_start', '22:00');
        HubSettings::set('quiet_end', '08:00');

        $this->assertNull(HubSettings::quietUntil(CarbonImmutable::parse('2026-10-24 12:00')));
        $this->assertSame('2026-10-24 08:00', HubSettings::quietUntil(CarbonImmutable::parse('2026-10-24 03:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25 08:00', HubSettings::quietUntil(CarbonImmutable::parse('2026-10-24 22:30'))->format('Y-m-d H:i'));
    }

    public function test_lead_recipients_get_sms_but_never_push(): void
    {
        $this->rule('lead.created', 'lead', ['channels' => ['sms', 'push']]);

        app(LeadService::class)->create(['name' => 'Nadia', 'phone' => '01511000001']);

        $this->assertSame('sent', NotificationDispatch::where('channel', 'sms')->where('recipient_type', CrmLead::class)->sole()->status);
        $this->assertSame('skipped', NotificationDispatch::where('channel', 'push')->where('recipient_type', CrmLead::class)->sole()->status);
    }
}
