<?php

use App\Models\Business;
use App\Models\User;

/*
| Every back-office screen is rendered at least once, so a Blade error cannot
| reach the browser without a test noticing first.
*/

it('renders every back-office screen', function (string $routeName, bool $needsBusiness, bool $needsUser) {
    $admin = platformAdmin();

    $parameters = match (true) {
        $needsBusiness => [Business::factory()->active()->create()],
        $needsUser => [User::factory()->create()],
        default => [],
    };

    $this->actingAs($admin)
        ->get(route($routeName, $parameters))
        ->assertOk();
})->with([
    'overview' => ['admin.dashboard', false, false],
    'business list' => ['admin.businesses.index', false, false],
    'new business' => ['admin.businesses.create', false, false],
    'business detail' => ['admin.businesses.show', true, false],
    'edit business' => ['admin.businesses.edit', true, false],
    'user list' => ['admin.users.index', false, false],
    'new user' => ['admin.users.create', false, false],
    'edit user' => ['admin.users.edit', false, true],
]);

it('renders the business workspace and the no-business page', function () {
    [$business, $user] = businessWithMember();

    $this->actingAs($user)->get(route('businesses.show', $business))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('no-business'))->assertOk();
});

it('renders the login screen without a registration link', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Register');
});

it('shows pharmacy mode as coming soon rather than offering it', function () {
    $this->actingAs(platformAdmin())
        ->get(route('admin.businesses.create'))
        ->assertOk()
        ->assertSee('coming soon', escape: false);
});
