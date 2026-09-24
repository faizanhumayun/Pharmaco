<?php

use Illuminate\Support\Facades\DB;

use App\Enums\BusinessRole;
use App\Enums\BusinessStatus;
use App\Enums\BusinessType;
use App\Enums\StockUnit;
use App\Models\Business;
use App\Models\User;

it('creates a business in setup status, not active', function () {
    $admin = platformAdmin();

    $this->actingAs($admin)->post(route('admin.businesses.store'), [
        'name' => 'ABC Pharma Distribution',
        'business_type' => 'distributor',
        'currency' => 'PKR',
        'timezone' => 'Asia/Karachi',
    ])->assertRedirect();

    $business = Business::where('name', 'ABC Pharma Distribution')->firstOrFail();

    expect($business->status)->toBe(BusinessStatus::Setup)
        ->and($business->opening_date)->toBeNull()
        ->and($business->acceptsTransactions())->toBeFalse()
        ->and($business->created_by)->toBe($admin->id);
});

it('creates multiple tenants that keep separate identities', function () {
    $admin = platformAdmin();

    foreach (['ABC Pharma Distribution', 'XYZ Pharma Distribution', 'Another Distribution'] as $name) {
        $this->actingAs($admin)->post(route('admin.businesses.store'), [
            'name' => $name,
            'business_type' => 'distributor',
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ])->assertRedirect();
    }

    expect(Business::count())->toBe(3)
        ->and(Business::pluck('slug')->unique())->toHaveCount(3);
});

it('gives businesses with the same name distinct slugs', function () {
    $admin = platformAdmin();

    foreach ([1, 2] as $ignored) {
        $this->actingAs($admin)->post(route('admin.businesses.store'), [
            'name' => 'Same Name Pharma',
            'business_type' => 'distributor',
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ]);
    }

    expect(Business::pluck('slug')->all())->toBe(['same-name-pharma', 'same-name-pharma-2']);
});

it('creates a pharmacy, counting in loose items unless told otherwise', function () {
    $this->actingAs(platformAdmin())
        ->post(route('admin.businesses.store'), [
            'name' => 'A Pharmacy',
            'business_type' => 'pharmacy',
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ])
        ->assertRedirect();

    $pharmacy = Business::firstOrFail();

    // A pharmacy breaks packs open and sells what the patient needs, so its
    // quantities are single items; a distributor's are whole packs.
    expect($pharmacy->business_type)->toBe(BusinessType::Pharmacy)
        ->and($pharmacy->unit())->toBe(StockUnit::Item);
});

it('refuses a kind of business that does not exist', function () {
    $this->actingAs(platformAdmin())
        ->post(route('admin.businesses.store'), [
            'name' => 'A Hospital',
            'business_type' => 'hospital',
            'currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ])
        ->assertSessionHasErrors('business_type');

    expect(Business::count())->toBe(0);
});

it('refuses to activate a business that has no finalized opening balance', function () {
    $business = Business::factory()->create();

    $this->actingAs(platformAdmin())
        ->patch(route('admin.businesses.status', $business), ['status' => BusinessStatus::Active->value])
        ->assertSessionHasErrors('status');

    expect($business->fresh()->status)->toBe(BusinessStatus::Setup);
});

it('allows suspension of an active business', function () {
    $business = Business::factory()->active()->create();

    $this->actingAs(platformAdmin())
        ->patch(route('admin.businesses.status', $business), ['status' => BusinessStatus::Suspended->value])
        ->assertRedirect();

    expect($business->fresh()->status)->toBe(BusinessStatus::Suspended);
});

it('refuses to archive a business that has financial history', function () {
    $business = Business::factory()->active()->create();

    $this->actingAs(platformAdmin())
        ->patch(route('admin.businesses.status', $business), ['status' => BusinessStatus::Archived->value])
        ->assertSessionHasErrors('status');
});

it('freezes the timezone once a business has financial history', function () {
    $business = Business::factory()->active()->create(['timezone' => 'Asia/Karachi']);

    $this->actingAs(platformAdmin())->put(route('admin.businesses.update', $business), [
        'name' => $business->name,
        'business_type' => 'distributor',
        'currency' => 'USD',
        'timezone' => 'UTC',
    ])->assertRedirect();

    $business->refresh();

    // Changing either would silently move historical transactions between days.
    expect($business->timezone)->toBe('Asia/Karachi')
        ->and($business->currency)->toBe('PKR');
});

it('deletes a business that never started trading', function () {
    $business = Business::factory()->create();
    app(App\Domain\Ledger\ChartOfAccounts::class)->seed($business);
    $name = $business->name;

    $this->actingAs(platformAdmin())
        ->delete(route('admin.businesses.destroy', $business))
        ->assertRedirect(route('admin.businesses.index'));

    expect(Business::where('name', $name)->exists())->toBeFalse()
        // Setup data goes with it; nothing financial existed to lose.
        ->and(App\Models\Account::acrossAllBusinesses()->where('business_id', $business->id)->count())->toBe(0);
});

it('deletes a business with financial history, and everything belonging to it', function () {
    [$business] = businessWithPostedDay();
    $id = $business->id;

    expect(App\Models\Transaction::forBusiness($business)->count())->toBeGreaterThan(0);

    $this->actingAs(platformAdmin())
        ->delete(route('admin.businesses.destroy', $business))
        ->assertRedirect(route('admin.businesses.index'));

    // A platform-level teardown: the models refuse to delete posted records,
    // and this is the single deliberate exception to that.
    expect(Business::whereKey($id)->exists())->toBeFalse();

    foreach (['transactions', 'ledger_entries', 'daily_entries', 'daily_closings',
              'opening_balances', 'accounts', 'expense_categories', 'business_user'] as $table) {
        expect(DB::table($table)->where('business_id', $id)->count())
            ->toBe(0, "{$table} still has rows for the deleted business");
    }
});

it('leaves no orphaned ledger rows behind', function () {
    [$business] = businessWithPostedDay();
    $id = $business->id;

    $this->actingAs(platformAdmin())->delete(route('admin.businesses.destroy', $business));

    expect(DB::table('ledger_entries')->count())->toBe(0)
        ->and(DB::table('expense_lines')->count())->toBe(0)
        ->and(DB::table('opening_balance_lines')->count())->toBe(0);
});

it('keeps the audit trail of a business it has just deleted', function () {
    [$business] = businessWithPostedDay();
    $id = $business->id;

    $this->actingAs(platformAdmin())->delete(route('admin.businesses.destroy', $business));

    // The records outlive the business they described, so the platform can
    // still answer what happened and who did it.
    expect(Spatie\Activitylog\Models\Activity::where('event', 'daily_entry.posted')->exists())->toBeTrue()
        ->and(Spatie\Activitylog\Models\Activity::where('business_id', $id)->exists())->toBeFalse();
});

it('records what was destroyed', function () {
    [$business] = businessWithPostedDay();

    $this->actingAs(platformAdmin())->delete(route('admin.businesses.destroy', $business));

    $activity = Spatie\Activitylog\Models\Activity::where('event', 'business.deleted')->firstOrFail();

    expect($activity->properties['business']['had_history'])->toBeTrue()
        ->and($activity->properties['business']['transactions'])->toBeGreaterThan(0);
});

it('lets nobody but the App Owner delete a business', function () {
    [, $owner] = businessWithMember();
    $business = Business::factory()->create();

    $this->actingAs($owner)
        ->delete(route('admin.businesses.destroy', $business))
        ->assertForbidden();
});

it('records the deletion on the platform audit trail', function () {
    $business = Business::factory()->create();
    $name = $business->name;

    $this->actingAs(platformAdmin())->delete(route('admin.businesses.destroy', $business));

    $activity = Spatie\Activitylog\Models\Activity::where('event', 'business.deleted')->firstOrFail();

    expect($activity->properties['business']['name'])->toBe($name)
        ->and($activity->causer)->not->toBeNull();
});

it('offers delete on the businesses list, with the state that decides it', function () {
    [$trading] = businessWithPostedDay();
    $fresh = Business::factory()->create(['name' => 'Never Traded Pharma']);

    $this->actingAs(platformAdmin())
        ->get(route('admin.businesses.index'))
        ->assertOk()
        ->assertSee('Delete')
        ->assertSee('Has records')
        ->assertSee('Setup only')
        ->assertSee('Delete permanently')
        // The warning names what would be lost rather than warning in the abstract.
        ->assertSee('ledger transactions', escape: false)
        ->assertSee('suspend', escape: false);
});

it('adds a member with a role and revokes without deleting the record', function () {
    $business = Business::factory()->active()->create();
    $user = User::factory()->create();
    $admin = platformAdmin();

    $this->actingAs($admin)->post(route('admin.businesses.members.store', $business), [
        'user_id' => $user->id,
        'role' => BusinessRole::Operator->value,
    ])->assertRedirect();

    expect($user->fresh()->roleIn($business))->toBe(BusinessRole::Operator);

    $this->actingAs($admin)
        ->delete(route('admin.businesses.members.destroy', [$business, $user]))
        ->assertRedirect();

    // The membership row survives so the audit trail can still name the person.
    expect($user->fresh()->belongsToBusiness($business))->toBeFalse()
        ->and($business->members()->where('users.id', $user->id)->exists())->toBeTrue();
});
