<?php

namespace App\Domain\Businesses\Actions;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;

class SetBusinessMember
{
    /** Adds a member, or updates their role if they are already one. */
    public function handle(Business $business, User $user, BusinessRole $role, bool $isActive = true): void
    {
        $business->members()->syncWithoutDetaching([
            $user->id => ['role' => $role->value, 'is_active' => $isActive],
        ]);

        // Keep Spatie's team-scoped role in step with the membership record.
        setPermissionsTeamId($business->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $user->syncRoles([$role->value]);
    }
}
