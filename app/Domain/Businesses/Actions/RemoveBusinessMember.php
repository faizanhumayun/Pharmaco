<?php

namespace App\Domain\Businesses\Actions;

use App\Models\Business;
use App\Models\User;

class RemoveBusinessMember
{
    /**
     * Membership is deactivated, not deleted: the audit trail needs to keep
     * naming whoever entered a historical transaction.
     */
    public function handle(Business $business, User $user): void
    {
        $business->members()->updateExistingPivot($user->id, ['is_active' => false]);

        setPermissionsTeamId($business->id);
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $user->syncRoles([]);
    }
}
