<?php

use App\Domain\Ledger\BalanceService;
use App\Domain\Opening\OpeningBalanceCalculator;
use App\Enums\AccountCode;
use App\Enums\BusinessStatus;
use App\Enums\OpeningBalanceStatus;
use App\Enums\TransactionType;
use App\Models\Business;
use App\Models\OpeningBalance;
use App\Models\Transaction;

/** The specification's own opening figures. */
function specificationFigures(array $overrides = []): array
{
    // array_replace, not array_merge: merge renumbers integer keys, and every
    // account code is one.
    return array_replace([
        'opening_date' => '2026-09-01',
        '1200' => '1000000',
        '1000' => '93',
        '1100' => '500000',
        '2000' => '3200000',
    ], $overrides);
}

function draftFor(Business $business, array $overrides = []): OpeningBalance
{
    test()->actingAs(platformAdmin())
        ->put(route('admin.businesses.opening.update', $business), specificationFigures($overrides));

    return $business->openingBalance()->first();
}

it('saves a draft without touching the ledger', function () {
    $business = setupBusiness();

    $opening = draftFor($business);

    expect($opening->status)->toBe(OpeningBalanceStatus::Draft)
        ->and($opening->lines)->toHaveCount(8)
        ->and(Transaction::forBusiness($business)->count())->toBe(0)
        ->and($business->fresh()->opening_date)->toBeNull();
});

it('computes the specification\'s position and finds it negative', function () {
    $business = setupBusiness();
    $opening = draftFor($business);

    $position = app(OpeningBalanceCalculator::class)->fromRecord($opening);

    expect($position->assets->toDecimal())->toBe('1500093.00')
        ->and($position->liabilities->toDecimal())->toBe('3200000.00')
        ->and($position->netPosition()->toDecimal())->toBe('-1699907.00')
        ->and($position->isNegative())->toBeTrue();
});

it('flags a balancing figure that dwarfs the assets declared', function () {
    $business = setupBusiness();
    $position = app(OpeningBalanceCalculator::class)->fromRecord(draftFor($business));

    // 1,699,907 against assets of 1,500,093 is 113%. The system should be
    // shouting, not quietly plugging the difference into equity.
    expect($position->balancingFigure()->toDecimal())->toBe('-1699907.00')
        ->and(round($position->balancingFigureRatio(), 2))->toBe(1.13)
        ->and($position->needsExplanation())->toBeTrue();
});

it('refuses to finalize an unexplained balancing figure', function () {
    $business = setupBusiness();
    draftFor($business);

    $this->actingAs(platformAdmin())
        ->post(route('admin.businesses.opening.finalize', $business), ['confirmed' => '1'])
        ->assertSessionHasErrors('notes');

    expect($business->fresh()->status)->toBe(BusinessStatus::Setup)
        ->and(Transaction::forBusiness($business)->count())->toBe(0);
});

it('finalizes once the position is explained', function () {
    $business = setupBusiness();
    draftFor($business);

    $this->actingAs(platformAdmin())
        ->post(route('admin.businesses.opening.finalize', $business), [
            'confirmed' => '1',
            'notes' => 'Trading on supplier credit; delivery van and shop deposit not yet valued.',
        ])
        ->assertRedirect(route('admin.businesses.opening.show', $business));

    $opening = $business->openingBalance()->first();

    expect($opening->status)->toBe(OpeningBalanceStatus::Finalized)
        ->and($opening->finalized_by)->not->toBeNull()
        ->and($opening->finalized_at)->not->toBeNull()
        ->and($opening->confirmation_text)->toContain('actual opening financial position')
        ->and($opening->net_position->toDecimal())->toBe('-1699907.00');
});

it('posts a balanced opening journal with the difference in Opening Balance Equity', function () {
    $business = setupBusiness();
    draftFor($business);

    $this->actingAs(platformAdmin())->post(route('admin.businesses.opening.finalize', $business), [
        'confirmed' => '1', 'notes' => 'Explained.',
    ]);

    $balances = app(BalanceService::class);
    $transaction = $business->openingBalance()->first()->transaction;

    expect($transaction->type)->toBe(TransactionType::Opening)
        ->and($balances->trialBalance($business)['balanced'])->toBeTrue()
        ->and($balances->asAt($business, AccountCode::Stock)->toDecimal())->toBe('1000000.00')
        ->and($balances->asAt($business, AccountCode::Cash)->toDecimal())->toBe('93.00')
        ->and($balances->asAt($business, AccountCode::MarketReceivables)->toDecimal())->toBe('500000.00')
        ->and($balances->asAt($business, AccountCode::CompanyPayables)->toDecimal())->toBe('3200000.00')
        ->and($balances->asAt($business, AccountCode::OpeningBalanceEquity)->toDecimal())->toBe('-1699907.00');
});

it('activates the business, which is earned rather than granted', function () {
    $business = setupBusiness();
    draftFor($business);

    expect($business->fresh()->acceptsTransactions())->toBeFalse();

    $this->actingAs(platformAdmin())->post(route('admin.businesses.opening.finalize', $business), [
        'confirmed' => '1', 'notes' => 'Explained.',
    ]);

    $business->refresh();

    expect($business->status)->toBe(BusinessStatus::Active)
        ->and($business->opening_date->toDateString())->toBe('2026-09-01')
        ->and($business->acceptsTransactions())->toBeTrue();
});

it('needs no explanation when the position balances on its own', function () {
    $business = setupBusiness();

    // Assets 1,500,093 against liabilities 500,000 and capital 1,000,093.
    draftFor($business, ['2000' => '500000', '3000' => '1000093']);

    $this->actingAs(platformAdmin())
        ->post(route('admin.businesses.opening.finalize', $business), ['confirmed' => '1'])
        ->assertSessionHasNoErrors();

    expect($business->openingBalance()->first()->balancing_figure->isZero())->toBeTrue();
});

it('refuses to finalize without the confirmation tick', function () {
    $business = setupBusiness();
    draftFor($business);

    $this->actingAs(platformAdmin())
        ->post(route('admin.businesses.opening.finalize', $business), ['notes' => 'Explained.'])
        ->assertSessionHasErrors('confirmed');
});

it('refuses to finalize an opening balance of nothing', function () {
    $business = setupBusiness();

    $this->actingAs(platformAdmin())->put(route('admin.businesses.opening.update', $business), [
        'opening_date' => '2026-09-01',
        '1200' => '0', '1000' => '0', '1100' => '0', '2000' => '0',
    ]);

    $this->actingAs(platformAdmin())
        ->post(route('admin.businesses.opening.finalize', $business), ['confirmed' => '1'])
        ->assertSessionHasErrors();
});

it('allows only one opening balance per business', function () {
    $business = setupBusiness();
    draftFor($business);
    draftFor($business, ['1000' => '500']);

    expect(OpeningBalance::forBusiness($business)->count())->toBe(1)
        ->and($business->openingBalance()->first()->lines()
            ->whereRelation('account', 'code', '1000')->first()->amount->toDecimal())->toBe('500.00');
});

it('rejects an opening date in the future', function () {
    $business = setupBusiness();

    $this->actingAs(platformAdmin())
        ->put(route('admin.businesses.opening.update', $business), specificationFigures([
            'opening_date' => now()->addWeek()->toDateString(),
        ]))
        ->assertSessionHasErrors('opening_date');
});

it('accepts grouped amounts from the form without ever touching a float', function () {
    $business = setupBusiness();

    $this->actingAs(platformAdmin())->put(route('admin.businesses.opening.update', $business), [
        'opening_date' => '2026-09-01',
        '1200' => '1,000,000.00', '1000' => '93', '1100' => '500,000', '2000' => '3,200,000',
    ])->assertSessionHasNoErrors();

    $position = app(OpeningBalanceCalculator::class)
        ->fromRecord($business->openingBalance()->first());

    expect($position->netPosition()->toDecimal())->toBe('-1699907.00');
});
