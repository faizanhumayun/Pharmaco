<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isPlatformAdmin() || $user->is($target);
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isPlatformAdmin();
    }

    /** Nobody may lock themselves out, and platform admins are never deleted. */
    public function deactivate(User $user, User $target): bool
    {
        return $user->isPlatformAdmin() && ! $user->is($target);
    }

    public function delete(User $user, User $target): bool
    {
        return false;
    }
}
