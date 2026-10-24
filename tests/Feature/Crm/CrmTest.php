<?php

namespace Tests\Feature\Crm;

use App\Actions\Crm\ConvertLeadToCustomer;
use App\Enums\Profiles\MasterProfileType;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Admin\Crm\Leads;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Models\Customer;
use App\Models\MasterProfile;
use App\Models\User;
use App\Services\Crm\CrmSettings;
use App\Services\Crm\LeadService;
use App\Sms\DTO\SmsResponse;
use App\Sms\SmsManager;
use Database\Seeders\AccountSeeder;
use Database\Seeders\AssignPermissionSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CRM (.scratch/notifications-crm/spec.md §7): leads move through stages,
 * get follow-ups, are visible per assignee, and convert to one customer
 * profile per phone. Every CRM and Notification Center page renders.
 */
class CrmTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

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

        $this->mock(SmsManager::class, fn ($m) => $m->shouldReceive('sendVia')->andReturn(SmsResponse::success('fake')));
        $this->withoutDefer();
    }

    protected function salesUser(string $name = 'Sales'): User
    {
        $role = Role::findOrCreate('crm-sales', 'web');
        $role->givePermissionTo('crm.view');
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    public function test_creating_a_lead_sets_stage_code_timeline_and_first_follow_up(): void
    {
        $lead = app(LeadService::class)->create(['name' => 'Nadia Islam', 'phone' => '+880 1711-000001', 'source' => 'facebook']);

        $this->assertSame('L-' . str_pad((string) $lead->id, 4, '0', STR_PAD_LEFT), $lead->fresh()->code);
        $this->assertSame('01711000001', $lead->phone);
        $this->assertSame(CrmStage::entry()->id, $lead->stage_id);
        $this->assertTrue($lead->activities()->where('type', 'system')->exists());
        $this->assertNotNull($lead->fresh()->next_follow_up_at, 'first contact follow-up');
    }

    public function test_new_lead_alerts_its_assignee_in_app(): void
    {
        $sales = $this->salesUser();

        app(LeadService::class)->create(['name' => 'Rafi', 'phone' => '01711000002', 'assigned_to' => $sales->id]);

        $note = $sales->notifications()->sole();
        $this->assertStringContainsString('New lead', $note->data['message']);
        $this->assertStringContainsString('/admin/crm/leads/', $note->data['url']);
    }

    public function test_round_robin_rotates_between_crm_users(): void
    {
        $a = $this->salesUser('A');
        $b = $this->salesUser('B');
        CrmSettings::set('auto_assign', 'round_robin');
        $svc = app(LeadService::class);

        $ids = collect(range(1, 4))->map(fn ($i) => $svc->create(['name' => "Lead {$i}", 'phone' => "0171100010{$i}"])->assigned_to);

        // admin (superadmin) is in the rotation too.
        $this->assertCount(3, $ids->unique());
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    public function test_losing_a_lead_records_the_reason_and_closes_follow_ups(): void
    {
        $svc = app(LeadService::class);
        $lead = $svc->create(['name' => 'Gone', 'phone' => '01711000003']);

        $svc->moveToStage($lead, CrmStage::lost(), 'Price too high');

        $lead->refresh();
        $this->assertSame('Price too high', $lead->lost_reason);
        $this->assertNotNull($lead->lost_at);
        $this->assertNull($lead->next_follow_up_at);
        $this->assertSame(0, $lead->activities()->openFollowUps()->count());
        $this->assertTrue($lead->activities()->where('type', 'stage')->exists());
    }

    public function test_convert_creates_customer_profile_and_marks_won(): void
    {
        $lead = app(LeadService::class)->create(['name' => 'Salma Begum', 'phone' => '01711000004', 'email' => 'salma@example.com', 'address' => 'Mirpur']);

        $customer = app(ConvertLeadToCustomer::class)->handle($lead);

        $lead->refresh();
        $this->assertNotNull($lead->converted_at);
        $this->assertSame(CrmStage::won()->id, $lead->stage_id);
        $this->assertSame($customer->id, $lead->customer_id);
        $profile = MasterProfile::findOrFail($lead->master_profile_id);
        $this->assertTrue($profile->hasRole('customer'));
        $this->assertSame('Salma', $customer->first_name);
        $this->assertSame('Begum', $customer->last_name);
        $this->assertSame('Mirpur', $profile->address);
        $this->assertSame(0, $lead->activities()->openFollowUps()->count());
    }

    public function test_convert_reuses_the_profile_that_already_has_the_phone(): void
    {
        $existing = MasterProfile::create(['uuid' => (string) Str::uuid(), 'type' => MasterProfileType::INDIVIDUAL->value, 'display_name' => 'Old Supplier', 'phone' => '01711000005', 'status' => 'active']);
        $existing->addRole('supplier');
        $lead = app(LeadService::class)->create(['name' => 'Old Supplier', 'phone' => '01711000005']);

        app(ConvertLeadToCustomer::class)->handle($lead);

        $this->assertSame(1, MasterProfile::where('phone', '01711000005')->count());
        $this->assertTrue($existing->fresh()->load('roles')->hasRole('customer'));
        $this->assertSame($existing->id, Customer::sole()->master_profile_id);
    }

    public function test_crm_view_users_only_see_their_own_leads(): void
    {
        $sales = $this->salesUser();
        $svc = app(LeadService::class);
        $mine = $svc->create(['name' => 'Zubair Mine', 'phone' => '01711000006', 'assigned_to' => $sales->id]);
        $other = $svc->create(['name' => 'Kamrul Notmine', 'phone' => '01711000007', 'assigned_to' => $this->admin->id]);

        $this->actingAs($sales);

        Livewire::test(Leads::class)->assertSee('Zubair Mine')->assertDontSee('Kamrul Notmine');
        Livewire::test(LeadShow::class, ['lead' => $mine])->assertOk();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(LeadShow::class, ['lead' => $other]);
    }

    public function test_leads_page_adds_a_lead_and_warns_about_a_duplicate_phone(): void
    {
        app(LeadService::class)->create(['name' => 'First', 'phone' => '01711000008']);

        Livewire::test(Leads::class)
            ->call('openCreate')
            ->set('form.name', 'Second')
            ->set('form.phone', '01711000008')
            ->assertSee('This phone number is already known')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, CrmLead::where('phone', '01711000008')->count());
    }

    public function test_kanban_move_changes_stage_and_lost_asks_for_reason(): void
    {
        $lead = app(LeadService::class)->create(['name' => 'Board', 'phone' => '01711000009']);
        $next = CrmStage::ordered()->get()[1];

        Livewire::test(Leads::class, ['view' => 'board'])
            ->call('moveLead', $lead->id, $next->id)
            ->call('moveLead', $lead->id, CrmStage::lost()->id)
            ->assertSet('lostModal', true)
            ->set('lostReason', 'No response')
            ->call('confirmLost');

        $this->assertSame(CrmStage::lost()->id, $lead->fresh()->stage_id);
        $this->assertSame('No response', $lead->fresh()->lost_reason);
    }

    public function test_lead_page_logs_a_call_and_completes_a_follow_up(): void
    {
        $lead = app(LeadService::class)->create(['name' => 'Caller', 'phone' => '01711000010']);
        $followUp = $lead->activities()->openFollowUps()->sole();

        Livewire::test(LeadShow::class, ['lead' => $lead])
            ->set('activityType', 'call')
            ->set('activityOutcome', 'interested')
            ->set('activityBody', 'Wants 5 pcs')
            ->call('logActivity')
            ->assertHasNoErrors()
            ->call('openComplete', $followUp->id)
            ->set('completeOutcome', 'answered')
            ->call('completeFollowUp');

        $this->assertTrue(CrmActivity::where('lead_id', $lead->id)->where('type', 'call')->where('outcome', 'interested')->exists());
        $this->assertNotNull($followUp->fresh()->done_at);
        $this->assertNotNull($lead->fresh()->last_contacted_at);
    }

    public function test_every_crm_and_notification_center_page_renders(): void
    {
        $lead = app(LeadService::class)->create(['name' => 'Page Lead', 'phone' => '01711000011']);

        foreach ([
            route('admin.crm.dashboard'), route('admin.crm.leads'), route('admin.crm.leads', ['view' => 'board']),
            route('admin.crm.leads.show', $lead), route('admin.crm.followups'), route('admin.crm.settings'),
            route('admin.notification-center.overview'), route('admin.notification-center.rules'),
            route('admin.notification-center.campaigns'), route('admin.notification-center.outbox'),
            route('admin.notification-center.settings'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_pages_are_forbidden_without_permission(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('admin.crm.leads'))->assertForbidden();
        $this->get(route('admin.notification-center.rules'))->assertForbidden();
    }
}
