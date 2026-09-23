<?php

namespace App\Http\Controllers\Business;

use App\Domain\Businesses\Actions\AddTeamMember;
use App\Domain\Businesses\Actions\UpdateTeamMember;
use App\Domain\Businesses\TeamRuleViolation;
use App\Enums\BusinessRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StoreTeamMemberRequest;
use App\Http\Requests\Business\UpdateTeamMemberRequest;
use App\Models\Business;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * The owner's view of who works in their business, and the logins they use.
 *
 * {member} resolves through Business::members() under scopeBindings, so only
 * someone already in this business can be addressed here.
 */
class TeamController extends Controller
{
    public function index(Business $business): View
    {
        $this->authorize('manageMembers', $business);

        $order = array_flip(array_column(BusinessRole::cases(), 'value'));

        return view('business.team.index', [
            'business' => $business,
            // Active people first, then by role in the order the roles are
            // defined (owner down to order booker), then by name.
            'members' => $business->members()->get()->sortBy([
                fn (User $a, User $b) => $b->pivot->is_active <=> $a->pivot->is_active,
                fn (User $a, User $b) => $order[$a->pivot->role->value] <=> $order[$b->pivot->role->value],
                fn (User $a, User $b) => strcasecmp($a->name, $b->name),
            ]),
            'roles' => BusinessRole::cases(),
        ]);
    }

    public function store(StoreTeamMemberRequest $request, Business $business, AddTeamMember $action): RedirectResponse
    {
        try {
            [$member, $created] = $action->handle($business, $request->validated(), $request->user());
        } catch (TeamRuleViolation $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.team.index', $business)
            ->with('status', $created
                ? "{$member->name} can now sign in with {$member->email} and the password you set."
                : "{$member->email} already had a login, so it was added to {$business->name} as it is. "
                    . 'They sign in with their existing password — the one typed here was not used.');
    }

    public function update(
        UpdateTeamMemberRequest $request,
        Business $business,
        User $member,
        UpdateTeamMember $action,
    ): RedirectResponse {
        try {
            $action->handle(
                $business,
                $member,
                BusinessRole::from($request->string('role')->toString()),
                $request->boolean('is_active'),
                $request->user(),
            );
        } catch (TeamRuleViolation $e) {
            throw ValidationException::withMessages(["member.{$member->id}" => $e->getMessage()]);
        }

        return redirect()
            ->route('businesses.team.index', $business)
            ->with('status', "{$member->name} updated.");
    }
}
