<?php

namespace App\NotificationHub;

use App\Models\CrmLead;
use App\Models\Customer;
use App\Models\MasterProfile;
use App\Models\User;
use App\Support\BdPhone;
use Illuminate\Database\Eloquent\Model;

/**
 * Someone a dispatch can go to, whatever they are underneath: a profile
 * (customer / supplier / employee / owner), a staff user or a lead. Push and
 * in-app need a login (user); SMS needs a phone; email needs an address.
 */
final class Recipient
{
    /** Extra placeholders for this person only (e.g. {due} in a due-segment campaign). */
    public array $data = [];

    public function __construct(
        public string $name,
        public ?string $phone = null,
        public ?string $email = null,
        public ?User $user = null,
        public ?Model $model = null,
    ) {
        $this->phone = BdPhone::normalize($phone) ?: null;
        $this->email = $email ? trim($email) : null;
    }

    public static function fromProfile(MasterProfile $profile): self
    {
        return new self($profile->display_name, $profile->phone ?: $profile->alt_phone, $profile->email, $profile->user, $profile);
    }

    /** A Customer row: through its profile when it has one, else its own contact fields. */
    public static function fromCustomer(Customer $customer): self
    {
        if ($customer->masterProfile) {
            return self::fromProfile($customer->masterProfile);
        }

        return new self((string) ($customer->full_name ?: trim("{$customer->first_name} {$customer->last_name}")), $customer->phone, $customer->email, $customer->user, $customer);
    }

    public static function fromUser(User $user): self
    {
        return new self((string) $user->name, $user->phone ?? null, $user->email, $user, $user);
    }

    public static function fromLead(CrmLead $lead): self
    {
        return new self($lead->name, $lead->phone, $lead->email, null, $lead);
    }

    /** Whatever was passed in a fire() context, as a Recipient (null when it can't be one). */
    public static function from(mixed $value): ?self
    {
        return match (true) {
            $value instanceof self => $value,
            $value instanceof MasterProfile => self::fromProfile($value),
            $value instanceof Customer => self::fromCustomer($value),
            $value instanceof User => self::fromUser($value),
            $value instanceof CrmLead => self::fromLead($value),
            default => null,
        };
    }

    public function profile(): ?MasterProfile
    {
        return $this->model instanceof MasterProfile ? $this->model : null;
    }

    public function lead(): ?CrmLead
    {
        return $this->model instanceof CrmLead ? $this->model : null;
    }

    /** What a dispatch is addressed to on this channel; null = nothing to send to. */
    public function address(string $channel): ?string
    {
        return match ($channel) {
            'sms' => $this->phone,
            'email' => $this->email,
            'push', 'database' => $this->user ? ($this->user->email ?: 'user #' . $this->user->id) : null,
            default => null,
        };
    }

    /** Same person, same channel → same key; used to drop duplicates (owner who is also staff). */
    public function key(): string
    {
        if ($this->model) {
            return $this->model::class . ':' . $this->model->getKey();
        }

        return 'adhoc:' . ($this->phone ?: $this->email ?: $this->name);
    }
}
