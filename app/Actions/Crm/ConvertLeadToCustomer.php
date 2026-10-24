<?php

namespace App\Actions\Crm;

use App\Concerns\CreatesMasterProfile;
use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Models\Customer;
use App\Models\MasterProfile;
use App\NotificationHub\NotificationHub;
use App\Services\Crm\CrmSettings;
use App\Services\Crm\LeadService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Lead → customer. Reuses the profile that already has the lead's phone (a
 * person can't get two profiles) or creates one; gives it the customer role
 * and a Customer row, links both back to the lead and marks the lead won.
 */
class ConvertLeadToCustomer
{
    use CreatesMasterProfile;

    public function __construct(protected NotificationHub $hub, protected LeadService $leads) {}

    public function handle(CrmLead $lead, ?int $existingProfileId = null): Customer
    {
        if ($lead->isConverted() && $lead->customer) {
            throw new RuntimeException('This lead is already a customer.');
        }

        $customer = DB::transaction(function () use ($lead, $existingProfileId) {
            $profile = $existingProfileId
                ? MasterProfile::findOrFail($existingProfileId)
                : ($lead->phone ? MasterProfile::where('phone', $lead->phone)->first() : null);

            [$first, $last] = $this->splitName($lead->name);

            $profile ??= $this->createMasterProfileFor([
                'display_name' => $lead->name,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'notes' => $lead->notes,
            ]);

            if (! $profile->address && $lead->address) {
                $profile->forceFill(['address' => $lead->address])->save();
            }

            $profile->addRole('customer');

            $customer = $profile->customer()->first() ?? Customer::create([
                'master_profile_id' => $profile->id,
                'customer_code' => $this->nextCustomerCode(),
                'first_name' => $first,
                'last_name' => $last,
                'full_name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone ?: $profile->phone,
                'status' => 'active',
                'notes' => $lead->notes,
            ]);

            $lead->forceFill([
                'converted_at' => now(),
                'master_profile_id' => $profile->id,
                'customer_id' => $customer->id,
            ])->save();

            if (CrmSettings::get('convert_moves_to_won') && ($won = CrmStage::won())) {
                $this->leads->moveToStage($lead, $won);
            }

            $lead->activities()->openFollowUps()->update(['done_at' => now(), 'outcome' => 'converted']);
            $lead->refreshNextFollowUp();
            $lead->log('system', "Converted to customer {$customer->customer_code} · profile {$profile->code}");
            $profile->logActivity('role', "Became a customer from lead {$lead->code}", ['lead_id' => $lead->id]);

            return $customer;
        });

        DB::afterCommit(fn () => $this->hub->fire('lead.converted', [
            'customer' => $customer->masterProfile,
            'data' => $this->leads->placeholders($lead),
            'related' => $lead,
            'url' => route('admin.crm.leads.show', $lead),
            'dedupe' => "lead-converted:{$lead->id}",
        ]));

        return $customer;
    }

    /** @return array{0: string, 1: ?string} */
    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [$name];

        return [$parts[0] ?: $name, $parts[1] ?? null];
    }

    protected function nextCustomerCode(): string
    {
        $n = (int) Customer::withTrashed()->max('id') + 1;

        do {
            $code = 'CUS-' . str_pad((string) $n++, 5, '0', STR_PAD_LEFT);
        } while (Customer::withTrashed()->where('customer_code', $code)->exists());

        return $code;
    }
}
