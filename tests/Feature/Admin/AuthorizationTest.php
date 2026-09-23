<?php

use App\Enums\BusinessRole;
use App\Enums\Permission;
use App\Models\Business;

it('gives the owner role every operator permission and more', function () {
    $roles = Permission::forRoles();

    expect($roles[BusinessRole::Operator->value])
        ->each->toBeIn($roles[BusinessRole::Owner->value]);
});

it('withholds position, closing and write-off permissions from operators', function () {
    $operator = Permission::forRoles()[BusinessRole::Operator->value];

    expect($operator)
        ->not->toContain(Permission::ReportViewPosition->value)
        ->not->toContain(Permission::ClosingFinalize->value)
        ->not->toContain(Permission::BadDebtWriteOff->value)
        ->not->toContain(Permission::OwnerEquityRecord->value)
        ->not->toContain(Permission::AuditView->value);
});

it('does not let platform admin status bypass business abilities', function () {
    $admin = platformAdmin();
    $business = Business::factory()->active()->create();

    // Gate::before grants platform abilities only — it is not a blanket bypass.
    // Archiving is still refused for a trading business even for the App Owner.
    expect($admin->can('archive', $business))->toBeFalse();
});

it('lets only the App Owner delete a business, at any stage', function () {
    $admin = platformAdmin();
    $trading = Business::factory()->active()->create();
    [$other, $owner] = businessWithMember();

    // Removing a tenant is a platform decision, so it is permitted — but it is
    // warned about in the interface and confirmed by typing the business name.
    expect($admin->can('delete', $trading))->toBeTrue()
        ->and($owner->can('delete', $other))->toBeFalse();
});

it('still refuses to delete a posted transaction, for everyone', function () {
    [$business, $owner] = ledgerBusiness();

    $transaction = postEntry($business, $owner, [
        App\Domain\Ledger\PostingLine::debit(App\Enums\AccountCode::Cash, '100.00'),
        App\Domain\Ledger\PostingLine::credit(App\Enums\AccountCode::Sales, '100.00'),
    ], App\Enums\TransactionType::SaleCash, reason: null);

    // Deleting a whole tenant is a platform teardown; editing or removing a
    // single record inside a live business remains impossible.
    $this->actingAs(platformAdmin());

    expect(fn () => $transaction->delete())
        ->toThrow(App\Exceptions\ImmutableRecordException::class);
});

it('allows archiving only while a business has no financial history', function () {
    $admin = platformAdmin();
    $fresh = Business::factory()->create();
    $trading = Business::factory()->active()->create();

    expect($admin->can('archive', $fresh))->toBeTrue()
        ->and($admin->can('archive', $trading))->toBeFalse();
});

it('lets a business owner manage members but not platform configuration', function () {
    [$business, $owner] = businessWithMember(BusinessRole::Owner);

    expect($owner->can('manageMembers', $business))->toBeTrue()
        ->and($owner->can('configure', $business))->toBeTrue()
        ->and($owner->can('update', $business))->toBeFalse()
        ->and($owner->can('viewAny', Business::class))->toBeFalse();
});

it('lets an operator view their business but change nothing about it', function () {
    [$business, $operator] = businessWithMember(BusinessRole::Operator);

    expect($operator->can('view', $business))->toBeTrue()
        ->and($operator->can('configure', $business))->toBeFalse()
        ->and($operator->can('manageMembers', $business))->toBeFalse();
});
