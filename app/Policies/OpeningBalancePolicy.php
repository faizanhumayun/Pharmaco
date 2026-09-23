<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Business;
use App\Models\OpeningBalance;
use App\Models\User;

/**
 * The App Owner is responsible for a business's starting financial position.
 *
 * The business owner sees it but cannot set it: the whole point of the opening
 * balance is that somebody independent of day-to-day operations agrees the
 * business started where it says it did.
 */
class OpeningBalancePolicy
{
    public function view(User $user, OpeningBalance $opening): bool
    {
        return $user->isPlatformAdmin()
            || $user->hasBusinessPermission($opening->business_id, Permission::OpeningBalanceView);
    }

    public function create(User $user, Business $business): bool
    {
        return $user->isPlatformAdmin();
    }

    public function update(User $user, OpeningBalance $opening): bool
    {
        return $user->isPlatformAdmin() && $opening->isEditable();
    }

    public function finalize(User $user, OpeningBalance $opening): bool
    {
        return $user->isPlatformAdmin() && $opening->isEditable();
    }

    /** After finalization the only route is an adjustment, and it is audited. */
    public function correct(User $user, OpeningBalance $opening): bool
    {
        return $user->isPlatformAdmin() && ! $opening->isEditable();
    }

    /** There is no un-finalize route, for anyone. */
    public function delete(User $user, OpeningBalance $opening): bool
    {
        return $user->isPlatformAdmin() && $opening->isEditable();
    }
}
