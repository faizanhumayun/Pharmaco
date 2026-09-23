<?php

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use App\Support\CurrentBusiness;

/*
|--------------------------------------------------------------------------
| Business isolation
|--------------------------------------------------------------------------
|
| A tenancy leak is silent and severe, so these are written first and kept
| passing forever. Every phase that adds a business-scoped table adds a case
| here.
|
*/

it('denies a member of one business access to another', function () {
    [$businessA, $userA] = businessWithMember(BusinessRole::Owner);
    $businessB = Business::factory()->active()->create();

    $this->actingAs($userA)
        ->get(route('businesses.show', $businessB))
        ->assertForbidden();
});

it('returns 403 rather than 404, so the response does not confirm the business exists', function () {
    [, $userA] = businessWithMember();
    $businessB = Business::factory()->active()->create();

    $response = $this->actingAs($userA)->get(route('businesses.show', $businessB));

    expect($response->status())->toBe(403);
});

it('allows a member into their own business', function () {
    [$business, $user] = businessWithMember(BusinessRole::Operator);

    $this->actingAs($user)
        ->get(route('businesses.show', $business))
        ->assertOk()
        ->assertSee($business->name);
});

it('denies a member whose access has been revoked', function () {
    [$business, $user] = businessWithMember();

    $business->members()->updateExistingPivot($user->id, ['is_active' => false]);

    $this->actingAs($user)
        ->get(route('businesses.show', $business))
        ->assertForbidden();
});

it('revokes access immediately when a user is deactivated, mid-session', function () {
    [$business, $user] = businessWithMember();

    $this->actingAs($user)->get(route('businesses.show', $business))->assertOk();

    $user->update(['is_active' => false]);

    $this->actingAs($user)
        ->get(route('businesses.show', $business))
        ->assertRedirect(route('login'));
});

it('lets a platform admin inspect any business', function () {
    $admin = platformAdmin();
    $business = Business::factory()->active()->create();

    $this->actingAs($admin)
        ->get(route('businesses.show', $business))
        ->assertOk();
});

it('keeps a user who is owner of one business and operator of another in the right role', function () {
    $user = User::factory()->create();
    [$first] = businessWithMember(BusinessRole::Owner, $user);
    [$second] = businessWithMember(BusinessRole::Operator, $user);

    expect($user->roleIn($first))->toBe(BusinessRole::Owner)
        ->and($user->roleIn($second))->toBe(BusinessRole::Operator)
        ->and($user->isOwnerOf($second))->toBeFalse();
});

it('sets the current business from the route and clears it outside a business request', function () {
    [$business, $user] = businessWithMember();

    $this->actingAs($user)->get(route('businesses.show', $business))->assertOk();

    // Resolved per request, never leaked into the next one.
    expect(app(CurrentBusiness::class)->id())->toBe($business->id);
});

it('never reads the business from user input', function () {
    [$businessA, $userA] = businessWithMember();
    $businessB = Business::factory()->active()->create();

    // A crafted business_id in the payload must not change which tenant is acted on.
    $this->actingAs($userA)
        ->get(route('businesses.show', $businessA) . '?business_id=' . $businessB->id)
        ->assertOk();

    expect(app(CurrentBusiness::class)->id())->toBe($businessA->id);
});
