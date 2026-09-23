<?php

use App\Models\User;

it('creates a user from the back office', function () {
    $this->actingAs(platformAdmin())->post(route('admin.users.store'), [
        'name' => 'Data Entry Operator',
        'email' => 'Operator@Example.Test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'operator@example.test')->firstOrFail();

    expect($user->is_active)->toBeTrue()
        ->and($user->isPlatformAdmin())->toBeFalse();
});

it('deactivates a user and revokes their access immediately', function () {
    $admin = platformAdmin();
    [$business, $member] = businessWithMember();

    $this->actingAs($admin)
        ->patch(route('admin.users.toggle-active', $member))
        ->assertRedirect();

    expect($member->fresh()->is_active)->toBeFalse();

    $this->actingAs($member->fresh())
        ->get(route('businesses.show', $business))
        ->assertRedirect(route('login'));
});

it('stops an admin deactivating themselves', function () {
    $admin = platformAdmin();

    $this->actingAs($admin)
        ->patch(route('admin.users.toggle-active', $admin))
        ->assertForbidden();

    expect($admin->fresh()->is_active)->toBeTrue();
});

it('stops an admin removing their own platform access', function () {
    $admin = platformAdmin();

    $this->actingAs($admin)->put(route('admin.users.update', $admin), [
        'name' => $admin->name,
        'email' => $admin->email,
        'is_platform_admin' => false,
    ])->assertRedirect();

    expect($admin->fresh()->isPlatformAdmin())->toBeTrue();
});

it('never exposes a route that deletes a user', function () {
    expect(Route::has('admin.users.destroy'))->toBeFalse();

    expect(platformAdmin()->can('delete', User::factory()->create()))->toBeFalse();
});

it('stops a business owner reaching user management', function () {
    [, $owner] = businessWithMember();

    $this->actingAs($owner)->get(route('admin.users.create'))->assertForbidden();
    $this->actingAs($owner)->post(route('admin.users.store'), [
        'name' => 'Sneaky', 'email' => 's@example.test',
        'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
    ])->assertForbidden();
});
