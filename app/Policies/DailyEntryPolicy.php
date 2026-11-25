<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Business;
use App\Models\DailyEntry;
use App\Models\User;

class DailyEntryPolicy
{
    public function viewAny(User $user, Business $business): bool
    {
        return $this->allows($user, $business, Permission::DailyEntryView);
    }

    public function view(User $user, DailyEntry $entry): bool
    {
        return $this->allows($user, $entry->business_id, Permission::DailyEntryView);
    }

    public function create(User $user, Business $business): bool
    {
        return $business->acceptsTransactions()
            && $this->allows($user, $business, Permission::DailyEntryCreate);
    }

    /**
     * A day stays editable until it is closed.
     *
     * Before the close nobody has agreed the figures and no report has been
     * issued, so correcting them is data entry rather than rewriting history.
     * Afterwards the period lock takes over and the only route is an adjustment.
     */
    public function update(User $user, DailyEntry $entry): bool
    {
        return $this->create($user, $entry->business)
            && ! $entry->business->isDayClosed($entry->business_date);
    }

    public function delete(User $user, DailyEntry $entry): bool
    {
        return false;
    }

    private function allows(User $user, Business|int $business, Permission $permission): bool
    {
        return $user->isPlatformAdmin() || $user->hasBusinessPermission($business, $permission);
    }
}
