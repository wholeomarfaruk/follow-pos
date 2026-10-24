<?php

namespace App\Services\Crm;

use App\Models\Setting;

/**
 * CRM › Settings, stored in the shared `settings` table under the "crm"
 * group.
 */
class CrmSettings
{
    public const GROUP = 'crm';

    public const DEFAULTS = [
        // none | fixed | round_robin
        'auto_assign' => 'none',
        'assign_user_id' => null,
        'round_robin_last' => null,
        // Every new lead gets a "first contact" follow-up this many hours out; 0 = none.
        'first_follow_up_hours' => 2,
        // Converting a lead moves it to the first "won" stage.
        'convert_moves_to_won' => true,
    ];

    public static function get(string $key): mixed
    {
        return Setting::get($key, self::DEFAULTS[$key] ?? null, self::GROUP);
    }

    public static function set(string $key, mixed $value): void
    {
        Setting::set($key, $value, self::GROUP);
    }
}
