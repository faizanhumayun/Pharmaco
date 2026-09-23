<?php

namespace App\Domain\Businesses\Actions;

use App\Domain\Businesses\TeamRuleViolation;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gives someone a login into this business.
 *
 * A person may work for more than one business — owner of one, operator of
 * another — so an email that already has a login is added to this business as
 * it is: their name and password stay theirs, and nothing typed here changes
 * them. Only a new email creates a new login.
 */
class AddTeamMember
{
    public function __construct(private readonly SetBusinessMember $setMember) {}

    /**
     * @param  array{name: string, email: string, password: string, role: string}  $data
     * @return array{0: User, 1: bool} the member, and whether their login was created here
     */
    public function handle(Business $business, array $data, User $by): array
    {
        $email = mb_strtolower(trim($data['email']));
        $role = BusinessRole::from($data['role']);

        $existing = User::query()->where('email', $email)->first();

        if ($existing?->isPlatformAdmin()) {
            throw new TeamRuleViolation(
                'That email belongs to the App Owner, who runs the platform and is not a member of any business.'
            );
        }

        if ($existing && $business->members()->whereKey($existing->id)->exists()) {
            throw new TeamRuleViolation(
                "{$existing->name} is already on this team. Change their role or reactivate them in the list below."
            );
        }

        return DB::transaction(function () use ($business, $data, $by, $email, $role, $existing) {
            $member = $existing ?? User::create([
                'name' => trim($data['name']),
                'email' => $email,
                'password' => $data['password'],
                'is_platform_admin' => false,
                'is_active' => true,
            ]);

            $this->setMember->handle($business, $member, $role);

            activity()
                ->causedBy($by)
                ->withProperties([
                    'member' => $member->email,
                    'role' => $role->value,
                    'login' => $existing ? 'existing' : 'created',
                ])
                ->event('team.member_added')
                ->log("{$member->name} added as {$role->label()}");

            return [$member, $existing === null];
        });
    }
}
