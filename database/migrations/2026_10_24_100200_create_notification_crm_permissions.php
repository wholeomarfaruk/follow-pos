<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CRM + Notification Center permissions, created here so live sites get
 * them on `migrate`. By name, not by a fixed id: ids above 201 are already
 * taken by the auto-increment rows of the Accounts Panel permissions.
 * PermissionSeeder creates the same names the same way.
 */
return new class extends Migration
{
    public const PERMISSIONS = [
        'crm.view',
        'crm.manage',
        'crm_settings.manage',
        'notification_center.view',
        'notification_center.manage',
        'notification_campaign.manage',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (['superadmin', 'admin'] as $role) {
            Role::query()->where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISSIONS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
