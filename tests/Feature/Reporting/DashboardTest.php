<?php

use App\Domain\Reporting\DashboardQuery;
use App\Enums\BusinessRole;
use App\Support\Money;

it('shows the position derived from the ledger', function () {
    [$business, $owner] = businessWithPostedDay();

    $data = app(DashboardQuery::class)->build($business);

    expect($data['cash']->toDecimal())->toBe('15093.00')
        ->and($data['receivables']->toDecimal())->toBe('630000.00')
        ->and($data['payables']->toDecimal())->toBe('3179000.00')
        ->and($data['stock']->toDecimal())->toBe('859000.00')
        ->and($data['position']->netPosition()->toDecimal())->toBe('-1674907.00')
        ->and($data['today']['grossProfit']->toDecimal())->toBe('30000.00')
        ->and($data['today']['netProfit']->toDecimal())->toBe('25000.00')
        ->and($data['today']['receivableDelta']->toDecimal())->toBe('130000.00');
});

it('reports the ledger as intact', function () {
    [$business] = businessWithPostedDay();

    $data = app(DashboardQuery::class)->build($business);

    expect($data['integrity']['trial']['balanced'])->toBeTrue()
        ->and($data['integrity']['positionConsistent'])->toBeTrue();
});

it('flags stock as never verified', function () {
    [$business] = businessWithPostedDay();

    $data = app(DashboardQuery::class)->build($business);

    expect($data['confidence']['verified'])->toBeFalse()
        ->and($data['confidence']['stale'])->toBeTrue()
        ->and(collect($data['alerts'])->pluck('title'))->toContain('Stock value unverified');
});

it('renders the dashboard for an owner with the position visible', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)
        ->get(route('businesses.show', $business))
        ->assertOk()
        ->assertSee('Net position')
        ->assertSee('-1,674,907.00')
        ->assertSee('estimated', escape: false);
});

it('hides the overall position from an operator', function () {
    [$business] = businessWithPostedDay();
    $operator = App\Models\User::factory()->create();
    $business->members()->attach($operator->id, ['role' => BusinessRole::Operator->value, 'is_active' => true]);

    $response = $this->actingAs($operator)->get(route('businesses.show', $business));

    // An operator does not need the business's financial standing to enter a day.
    $response->assertOk()
        ->assertDontSee('Net position')
        ->assertSee('Gross profit');
});

it('shows the setup placeholder before an opening balance exists', function () {
    $business = setupBusiness();
    $owner = App\Models\User::factory()->create();
    $business->members()->attach($owner->id, ['role' => BusinessRole::Owner->value, 'is_active' => true]);

    $this->actingAs($owner)
        ->get(route('businesses.show', $business))
        ->assertOk()
        ->assertSee('Opening balance not set');
});

it('keeps the unexplained opening equity visible on the dashboard', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)
        ->get(route('businesses.show', $business))
        ->assertSee('still unexplained');
});
