<?php

use App\Domain\Ledger\BalanceService;
use App\Domain\Ledger\PostingLine;
use App\Enums\AccountCode;
use App\Enums\BusinessRole;
use App\Enums\TransactionType;
use App\Exceptions\ClosedPeriodException;
use App\Models\DailyClosing;
use App\Models\DailyEntry;

/** A trading business with the specification's worked day posted. */
function businessWithPostedDay(): array
{
    [$business, $owner] = tradingBusiness();

    test()->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput());
    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    test()->actingAs($owner)->post(route('businesses.daily.post', [$business, $entry]));

    return [$business->fresh(), $owner];
}

it('computes the closing figures from the ledger', function () {
    [$business, $owner] = businessWithPostedDay();

    $figures = app(App\Domain\Closing\DailyClosingCalculator::class)
        ->compute($business, $business->today());

    expect($figures['closing_cash']->toDecimal())->toBe('15093.00')
        ->and($figures['closing_receivable']->toDecimal())->toBe('630000.00')
        ->and($figures['closing_payable']->toDecimal())->toBe('3179000.00')
        ->and($figures['closing_stock']->toDecimal())->toBe('859000.00')
        ->and($figures['gross_profit']->toDecimal())->toBe('30000.00')
        ->and($figures['net_profit']->toDecimal())->toBe('25000.00')
        ->and($figures['receivable_delta']->toDecimal())->toBe('130000.00');
});

it('blocks closing until the cash has been counted', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)
        ->post(route('businesses.closing.finalize', [$business, $business->today()->toDateString()]))
        ->assertSessionHasErrors('status');

    expect($business->fresh()->locked_through_date)->toBeNull();
});

it('blocks closing while a day still has a draft entry', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput());

    $this->actingAs($owner)->post(route('businesses.closing.reconcile', [$business, '2026-09-01']), [
        'counted_cash' => '93',
    ]);

    $this->actingAs($owner)
        ->post(route('businesses.closing.finalize', [$business, '2026-09-01']))
        ->assertSessionHasErrors('status');
});

it('closes a day once the checks pass', function () {
    [$business, $owner] = businessWithPostedDay();
    $date = $business->today()->toDateString();

    $this->actingAs($owner)->post(route('businesses.closing.reconcile', [$business, $date]), [
        'counted_cash' => '15093',
    ])->assertRedirect();

    $this->actingAs($owner)
        ->post(route('businesses.closing.finalize', [$business, $date]))
        ->assertRedirect();

    $closing = DailyClosing::forBusiness($business)->firstOrFail();

    expect($closing->isFinalized())->toBeTrue()
        ->and($closing->finalized_by)->toBe($owner->id)
        ->and($business->fresh()->locked_through_date->toDateString())->toBe($date);
});

it('stops anything posting into a closed day afterwards', function () {
    [$business, $owner] = businessWithPostedDay();
    $date = $business->today()->toDateString();

    $this->actingAs($owner)->post(route('businesses.closing.reconcile', [$business, $date]), ['counted_cash' => '15093']);
    $this->actingAs($owner)->post(route('businesses.closing.finalize', [$business, $date]));

    expect(fn () => postEntry($business->fresh(), $owner, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, date: $date, reason: null))->toThrow(ClosedPeriodException::class);
});

it('posts a cash shortage as a real transaction rather than a note', function () {
    [$business, $owner] = businessWithPostedDay();
    $date = $business->today()->toDateString();
    $balances = app(BalanceService::class);

    $this->actingAs($owner)->post(route('businesses.closing.reconcile', [$business, $date]), [
        'counted_cash' => '13093',
        'variance_reason' => 'Owner took 2,000 for fuel and did not record it.',
    ])->assertRedirect();

    $closing = DailyClosing::forBusiness($business)->firstOrFail();

    // The ledger and the safe agree afterwards, and the difference is explained.
    expect($closing->cash_variance->toDecimal())->toBe('-2000.00')
        ->and($balances->asAt($business, AccountCode::Cash)->toDecimal())->toBe('13093.00')
        ->and($balances->trialBalance($business)['balanced'])->toBeTrue();
});

it('refuses an unexplained cash difference', function () {
    [$business, $owner] = businessWithPostedDay();

    $this->actingAs($owner)
        ->post(route('businesses.closing.reconcile', [$business, $business->today()->toDateString()]), [
            'counted_cash' => '13093',
        ])
        ->assertSessionHasErrors('variance_reason');
});

it('closes days in sequence and refuses to skip one', function () {
    [$business, $owner] = businessWithPostedDay();

    // 2026-09-01 has not been closed, so 2026-09-02 cannot be.
    $this->actingAs($owner)->post(route('businesses.closing.reconcile', [$business, '2026-09-02']), [
        'counted_cash' => '15093',
    ]);

    $this->actingAs($owner)
        ->post(route('businesses.closing.finalize', [$business, '2026-09-02']))
        ->assertSessionHasErrors('status');
});

it('reopens the most recent closed day with a reason', function () {
    [$business, $owner] = businessWithPostedDay();
    $date = $business->today()->toDateString();

    $this->actingAs($owner)->post(route('businesses.closing.reconcile', [$business, $date]), ['counted_cash' => '15093']);
    $this->actingAs($owner)->post(route('businesses.closing.finalize', [$business, $date]));

    $this->actingAs($owner)->post(route('businesses.closing.reopen', [$business, $date]), [
        'reason' => 'Company invoice for this day arrived late.',
    ])->assertRedirect();

    // The superseded closing is kept; the lock rolls back.
    expect($business->fresh()->locked_through_date)->toBeNull()
        ->and(DailyClosing::forBusiness($business)->firstOrFail()->status)->toBe('superseded');
});

it('demands a reason to reopen', function () {
    [$business, $owner] = businessWithPostedDay();
    $date = $business->today()->toDateString();

    $this->actingAs($owner)->post(route('businesses.closing.reconcile', [$business, $date]), ['counted_cash' => '15093']);
    $this->actingAs($owner)->post(route('businesses.closing.finalize', [$business, $date]));

    $this->actingAs($owner)
        ->post(route('businesses.closing.reopen', [$business, $date]), ['reason' => ''])
        ->assertSessionHasErrors('reason');
});

it('stops an operator closing or reopening a day', function () {
    [$business] = businessWithPostedDay();
    $operator = App\Models\User::factory()->create();
    $business->members()->attach($operator->id, ['role' => BusinessRole::Operator->value, 'is_active' => true]);
    $date = $business->today()->toDateString();

    $this->actingAs($operator)
        ->post(route('businesses.closing.finalize', [$business, $date]))
        ->assertForbidden();
});

it('rebuilds every stored closing to exactly the same figures', function () {
    [$business, $owner] = businessWithPostedDay();
    $date = $business->today()->toDateString();

    $this->actingAs($owner)->post(route('businesses.closing.reconcile', [$business, $date]), ['counted_cash' => '15093']);
    $this->actingAs($owner)->post(route('businesses.closing.finalize', [$business, $date]));

    // The check that keeps daily_closings a cache rather than a second source
    // of truth. If this ever fails, the requirement is broken.
    $this->artisan('closings:rebuild', ['--business' => $business->slug, '--check' => true])
        ->assertSuccessful();
});

it('notices when a stored closing drifts from the ledger', function () {
    [$business, $owner] = businessWithPostedDay();
    $date = $business->today()->toDateString();

    $this->actingAs($owner)->post(route('businesses.closing.reconcile', [$business, $date]), ['counted_cash' => '15093']);
    $this->actingAs($owner)->post(route('businesses.closing.finalize', [$business, $date]));

    DailyClosing::forBusiness($business)->firstOrFail()->forceFill(['net_profit' => '999999.00'])->save();

    $this->artisan('closings:rebuild', ['--business' => $business->slug, '--check' => true])
        ->assertFailed();
});
