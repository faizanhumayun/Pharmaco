<?php

use App\Models\Business;
use App\Models\User;

it('keeps non-platform users out of the back office', function () {
    [, $owner] = businessWithMember();

    $this->actingAs($owner)->get(route('admin.dashboard'))->assertForbidden();
    $this->actingAs($owner)->get(route('admin.businesses.index'))->assertForbidden();
    $this->actingAs($owner)->get(route('admin.users.index'))->assertForbidden();
});

it('lets the platform admin into the back office', function () {
    $this->actingAs(platformAdmin())
        ->get(route('admin.dashboard'))
        ->assertOk();
});

it('redirects guests to login', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

it('has no public registration route', function () {
    expect(Route::has('register'))->toBeFalse();

    $this->post('/register', [
        'name' => 'Uninvited',
        'email' => 'uninvited@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});

it('sends a platform admin to the back office after login', function () {
    $admin = platformAdmin();

    expect($admin->homeRoute())->toBe(route('admin.dashboard'));
});

it('sends a business member to their business after login', function () {
    [$business, $user] = businessWithMember();

    expect($user->homeRoute())->toBe(route('businesses.show', $business));
});

it('tells a user with no business that they have no access yet', function () {
    $user = User::factory()->create();

    expect($user->homeRoute())->toBe(route('no-business'));
});
