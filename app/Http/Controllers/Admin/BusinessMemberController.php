<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Businesses\Actions\RemoveBusinessMember;
use App\Domain\Businesses\Actions\SetBusinessMember;
use App\Enums\BusinessRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBusinessMemberRequest;
use App\Models\Business;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class BusinessMemberController extends Controller
{
    public function store(StoreBusinessMemberRequest $request, Business $business, SetBusinessMember $action): RedirectResponse
    {
        $user = User::findOrFail($request->integer('user_id'));

        $action->handle($business, $user, BusinessRole::from($request->string('role')->toString()));

        return back()->with('status', "{$user->name} added to {$business->name}.");
    }

    public function destroy(Business $business, User $user, RemoveBusinessMember $action): RedirectResponse
    {
        $this->authorize('manageMembers', $business);

        $action->handle($business, $user);

        return back()->with('status', "{$user->name} no longer has access to {$business->name}.");
    }
}
