<?php

namespace Database\Seeders;

use App\Enums\BusinessRole;
use App\Enums\Permission as AppPermission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the permission registry and the two business roles.
 *
 * Idempotent, so it can run again after new permissions are added in a later
 * phase without disturbing existing assignments.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (AppPermission::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Roles are defined globally (team_id null) and assigned per business;
        // Spatie scopes the assignment, not the definition.
        setPermissionsTeamId(null);

        foreach (AppPermission::forRoles() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
        }

        $this->command?->info(
            sprintf(
                'Seeded %d permissions across %d roles (%s).',
                count(AppPermission::values()),
                count(BusinessRole::cases()),
                implode(', ', array_column(BusinessRole::cases(), 'value'))
            )
        );
    }
}
