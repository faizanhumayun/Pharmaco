<?php

use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingSpec;
use App\Enums\BusinessRole;
use App\Enums\TransactionType;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** The App Owner: operates the platform, belongs to no business. */
function platformAdmin(array $attributes = []): User
{
    return User::factory()->platformAdmin()->create($attributes);
}

/** A business with an active member in the given role. */
function businessWithMember(BusinessRole $role = BusinessRole::Owner, ?User $user = null): array
{
    $business = Business::factory()->active()->create();
    $user ??= User::factory()->create();

    $business->members()->attach($user->id, [
        'role' => $role->value,
        'is_active' => true,
    ]);

    return [$business->fresh(), $user->fresh()];
}

/** A business still in setup — no opening balance yet — with its chart seeded. */
function setupBusiness(): \App\Models\Business
{
    $business = \App\Models\Business::factory()->create();

    app(ChartOfAccounts::class)->seed($business);

    return $business->fresh();
}

/** An active business with its chart of accounts seeded, plus an owner. */
function ledgerBusiness(): array
{
    [$business, $user] = businessWithMember(BusinessRole::Owner);

    app(ChartOfAccounts::class)->seed($business);

    return [$business, $user];
}

/** Posts one balanced transaction and returns it. */
function postEntry(
    \App\Models\Business $business,
    \App\Models\User $by,
    array $lines,
    TransactionType $type = TransactionType::Adjustment,
    ?string $date = null,
    ?string $reason = 'test posting',
): \App\Models\Transaction {
    return app(LedgerPoster::class)->post(new PostingSpec(
        business: $business,
        date: $date ? \Illuminate\Support\Carbon::parse($date) : $business->today(),
        type: $type,
        lines: $lines,
        createdBy: $by,
        narration: 'test',
        reason: $reason,
    ));
}
