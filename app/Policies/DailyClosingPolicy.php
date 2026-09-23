<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Business;
use App\Models\DailyClosing;
use App\Models\User;

class DailyClosingPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::ClosingView);
    }

    /** Counting the cash is part of entering the day; agreeing it is right is not. */
    public function reconcile(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::ClosingView);
    }

    public function finalize(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::ClosingFinalize);
    }

    public function reopen(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::ClosingReopen);
    }

    /** A closed day is never edited, and never deleted. */
    public function delete(User $user, DailyClosing $closing): bool
    {
        return false;
    }

    private function allows(User $user, Business $business, Permission $permission): bool
    {
        return $user->isPlatformAdmin() || $user->hasBusinessPermission($business, $permission);
    }
}
