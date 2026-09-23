<?php

use App\Domain\Ledger\BalanceService;
use App\Domain\Stock\StockConfidence;
use App\Enums\AccountCode;
use App\Enums\BusinessRole;
use App\Models\StockVerification;

it('posts a shortfall to profit and loss, not as a quiet correction', function () {
    [$business, $owner] = businessWithPostedDay();
    $balances = app(BalanceService::class);

    // Book says 859,000; the count finds 840,000 — expiry and breakage that the
    // derived cost model cannot see.
    $this->actingAs($owner)->post(route('businesses.stock.store', $business), [
        'business_date' => $business->today()->toDateString(),
        'counted_value' => '840000',
        'reason' => 'Expiry write-off found in Godown 2.',
    ])->assertRedirect();

    expect($balances->asAt($business, AccountCode::Stock)->toDecimal())->toBe('840000.00')
        ->and($balances->asAt($business, AccountCode::StockVariance)->toDecimal())->toBe('19000.00')
        ->and($balances->trialBalance($business)['balanced'])->toBeTrue();
});

it('records a surplus the same way', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)->post(route('businesses.stock.store', $business), [
        'business_date' => $business->today()->toDateString(),
        'counted_value' => '870000',
        'reason' => 'Goods received but not booked.',
    ]);

    expect(app(BalanceService::class)->asAt($business, AccountCode::Stock)->toDecimal())
        ->toBe('870000.00');
});

it('reduces net profit by the shortfall', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)->post(route('businesses.stock.store', $business), [
        'business_date' => $business->today()->toDateString(),
        'counted_value' => '840000',
        'reason' => 'Expiry write-off.',
    ]);

    $data = app(App\Domain\Reporting\DashboardQuery::class)->build($business);

    // Gross profit is unchanged; net profit carries the loss.
    expect($data['today']['grossProfit']->toDecimal())->toBe('30000.00')
        ->and($data['today']['netProfit']->toDecimal())->toBe('6000.00');
});

it('reports confidence once stock has been counted', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)->post(route('businesses.stock.store', $business), [
        'business_date' => $business->today()->toDateString(),
        'counted_value' => '840000',
        'reason' => 'Count.',
    ]);

    $confidence = app(StockConfidence::class)->for($business);

    expect($confidence['verified'])->toBeTrue()
        ->and($confidence['stale'])->toBeFalse()
        ->and($confidence['label'])->toBe('verified today')
        ->and(round($confidence['drift'], 2))->toBe(-2.21);
});

it('records a matching count with no posting at all', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)->post(route('businesses.stock.store', $business), [
        'business_date' => $business->today()->toDateString(),
        'counted_value' => '859000',
    ]);

    $verification = StockVerification::forBusiness($business)->firstOrFail();

    expect($verification->variance->isZero())->toBeTrue()
        ->and($verification->transaction_id)->toBeNull();
});

it('stops an operator verifying stock', function () {
    [$business] = businessWithPostedDay();
    $operator = App\Models\User::factory()->create();
    $business->members()->attach($operator->id, ['role' => BusinessRole::Operator->value, 'is_active' => true]);

    $this->actingAs($operator)
        ->post(route('businesses.stock.store', $business), [
            'business_date' => $business->today()->toDateString(),
            'counted_value' => '1',
        ])
        ->assertForbidden();
});
