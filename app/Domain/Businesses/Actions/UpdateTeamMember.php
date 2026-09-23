<?php

namespace App\Domain\Businesses\Actions;

use App\Domain\Businesses\TeamRuleViolation;
use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Changes someone's role in a business, or their access to it.
 *
 * Access is switched off rather than removed: the audit trail has to keep
 * naming whoever entered a historical transaction.
 */
class UpdateTeamMember
{
    public function __construct(
        private readonly SetBusinessMember $setMember,
        private readonly RemoveBusinessMember $removeMember,
    ) {}

    public function handle(Business $business, User $member, BusinessRole $role, bool $active, User $by): void
    {
        // Demoting or switching off yourself is how a business loses its last
        // way in. Another owner, or the App Owner, can do it instead.
        if ($member->is($by)) {
            throw new TeamRuleViolation('You cannot change your own role or access. Ask another owner or the App Owner.');
        }

        $before = $business->members()->whereKey($member->id)->firstOrFail()->pivot;

        $wasActiveOwner = $before->is_active && $before->role === BusinessRole::Owner;
        $staysActiveOwner = $active && $role === BusinessRole::Owner;

        if ($wasActiveOwner && ! $staysActiveOwner && $this->activeOwnerCount($business) <= 1) {
            throw new TeamRuleViolation(
                "{$member->name} is the only active owner. Make someone else an owner first — a business always needs one."
            );
        }

        DB::transaction(function () use ($business, $member, $role, $active, $by, $before) {
            if ($active) {
                $this->setMember->handle($business, $member, $role);
            } else {
                // The role is kept on the record for if they come back.
                $business->members()->updateExistingPivot($member->id, ['role' => $role->value]);
                $this->removeMember->handle($business, $member);
            }

            activity()
                ->causedBy($by)
                ->withProperties([
                    'member' => $member->email,
                    'before' => ['role' => $before->role?->value, 'active' => (bool) $before->is_active],
                    'after' => ['role' => $role->value, 'active' => $active],
                ])
                ->event('team.member_updated')
                ->log("{$member->name}: " . ($active ? $role->label() : 'access switched off'));
        });
    }

    private function activeOwnerCount(Business $business): int
    {
        return $business->members()
            ->wherePivot('is_active', true)
            ->wherePivot('role', BusinessRole::Owner->value)
            ->count();
    }
}
