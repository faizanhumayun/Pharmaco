<?php

use App\Domain\Ledger\BalanceService;
use App\Enums\AccountCode;
use App\Enums\BusinessRole;
use App\Enums\DocumentStatus;
use App\Exceptions\ImmutableRecordException;
use App\Models\DailyEntry;
use App\Models\Transaction;

/** A business whose opening position is finalized and which can trade. */
function tradingBusiness(): array
{
    $business = setupBusiness();
    $admin = platformAdmin();

    test()->actingAs($admin)->put(route('admin.businesses.opening.update', $business), array_replace([
        'opening_date' => '2026-08-31',
        '1200' => '1000000', '1000' => '93', '1100' => '500000', '2000' => '3200000',
    ], []));

    test()->actingAs($admin)->post(route('admin.businesses.opening.finalize', $business), [
        'confirmed' => '1', 'notes' => 'Trading on supplier credit.',
    ]);

    $owner = App\Models\User::factory()->create();
    $business->members()->attach($owner->id, ['role' => BusinessRole::Owner->value, 'is_active' => true]);

    return [$business->fresh(), $owner];
}

/** The specification's worked day. */
function workedDayInput(array $overrides = []): array
{
    return array_replace([
        'business_date' => '2026-09-01',
        'purchase_total' => '150000',
        'purchase_paid' => '0',
        'purchase_discount' => '21000',
        'sale_cash' => '120000',
        'sale_credit' => '180000',
        'gross_profit' => '30000',
        'collection_cash' => '50000',
        'company_payment_cash' => '150000',
        'expenses_cash' => '5000',
        'company_note' => 'Getz, Abbott',
    ], $overrides);
}

it('saves a day as a draft with no ledger effect', function () {
    [$business, $owner] = tradingBusiness();
    $before = Transaction::forBusiness($business)->count();

    $this->actingAs($owner)
        ->post(route('businesses.daily.store', $business), workedDayInput())
        ->assertRedirect();

    $entry = DailyEntry::forBusiness($business)->firstOrFail();

    expect($entry->status)->toBe(DocumentStatus::Draft)
        ->and(Transaction::forBusiness($business)->count())->toBe($before);
});

it('computes totals rather than accepting them', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput());
    $entry = DailyEntry::forBusiness($business)->firstOrFail();

    expect($entry->totalPurchases()->toDecimal())->toBe('150000.00')
        ->and($entry->costAddedToStock()->toDecimal())->toBe('129000.00')
        ->and($entry->totalSales()->toDecimal())->toBe('300000.00')
        ->and($entry->costOfGoodsSold()->toDecimal())->toBe('270000.00')
        ->and($entry->netProfit()->toDecimal())->toBe('25000.00')
        ->and(round($entry->marginPercent(), 2))->toBe(10.0);
});

it('posts the worked day to exactly the figures the design predicted', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput());
    $entry = DailyEntry::forBusiness($business)->firstOrFail();

    $this->actingAs($owner)
        ->post(route('businesses.daily.post', [$business, $entry]))
        ->assertRedirect();

    $balances = app(BalanceService::class);

    expect($balances->asAt($business, AccountCode::Cash)->toDecimal())->toBe('15093.00')
        ->and($balances->asAt($business, AccountCode::MarketReceivables)->toDecimal())->toBe('630000.00')
        ->and($balances->asAt($business, AccountCode::CompanyPayables)->toDecimal())->toBe('3179000.00')
        ->and($balances->asAt($business, AccountCode::Stock)->toDecimal())->toBe('859000.00')
        ->and($balances->trialBalance($business)['balanced'])->toBeTrue()
        ->and($balances->position($business)->isConsistent())->toBeTrue();
});

it('produces one transaction per kind of activity from a single document', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput());
    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    $this->actingAs($owner)->post(route('businesses.daily.post', [$business, $entry]));

    // Purchase, two sales, COGS, collection, payment, expense.
    expect($entry->fresh()->transactions)->toHaveCount(7);
});

it('keeps the trade discount out of stock', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput([
        'sale_cash' => '0', 'sale_credit' => '0', 'gross_profit' => '0',
        'collection_cash' => '0', 'company_payment_cash' => '0', 'expenses_cash' => '0',
    ]));
    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    $this->actingAs($owner)->post(route('businesses.daily.post', [$business, $entry]));

    $balances = app(BalanceService::class);

    // 150,000 invoiced less 21,000 discount adds 129,000 of cost, not 150,000.
    expect($balances->asAt($business, AccountCode::Stock)->toDecimal())->toBe('1129000.00')
        ->and($balances->asAt($business, AccountCode::CompanyPayables)->toDecimal())->toBe('3329000.00');
});

it('refuses a second entry for the same day at the database level', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput());
    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    $this->actingAs($owner)->post(route('businesses.daily.post', [$business, $entry]));

    // A posted day cannot be overwritten — the unique constraint plus the
    // immutability guard, not UI discipline, are what stop a day doubling.
    $this->actingAs($owner)
        ->post(route('businesses.daily.store', $business), workedDayInput(['sale_cash' => '999999']))
        ->assertSessionHasErrors('business_date');

    expect(DailyEntry::forBusiness($business)->count())->toBe(1)
        ->and(app(BalanceService::class)->asAt($business, AccountCode::Cash)->toDecimal())->toBe('15093.00');
});

it('refuses to edit a posted day', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput());
    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    $this->actingAs($owner)->post(route('businesses.daily.post', [$business, $entry]));

    expect(fn () => $entry->fresh()->update(['sale_cash' => '1.00']))
        ->toThrow(ImmutableRecordException::class);
});

it('blocks gross profit that exceeds net sales', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)
        ->post(route('businesses.daily.store', $business), workedDayInput(['gross_profit' => '400000']))
        ->assertSessionHasErrors('gross_profit');
});

it('blocks a trade discount larger than the purchase it applies to', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)
        ->post(route('businesses.daily.store', $business), workedDayInput(['purchase_discount' => '200000']))
        ->assertSessionHasErrors('purchase_discount');
});

it('blocks returns larger than the day\'s sales', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)
        ->post(route('businesses.daily.store', $business), workedDayInput(['sales_return' => '400000']))
        ->assertSessionHasErrors('sales_return');
});

it('blocks a future business date', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)
        ->post(route('businesses.daily.store', $business), workedDayInput([
            'business_date' => now()->addWeek()->toDateString(),
        ]))
        ->assertSessionHasErrors('business_date');
});

it('records owner drawings against equity rather than profit', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput([
        'owner_drawing' => '10000',
    ]));
    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    $this->actingAs($owner)->post(route('businesses.daily.post', [$business, $entry]));

    $balances = app(BalanceService::class);

    // Cash falls, but profit is untouched — the most common reason a cash
    // balance stops reconciling is that this has nowhere to go.
    expect($balances->asAt($business, AccountCode::Cash)->toDecimal())->toBe('5093.00')
        ->and($balances->asAt($business, AccountCode::OwnerDrawings)->toDecimal())->toBe('10000.00')
        ->and($entry->fresh()->netProfit()->toDecimal())->toBe('25000.00');
});

it('clears a receivable through discount and write-off without cash', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput([
        'discount_allowed' => '2000',
        'bad_debt' => '3000',
    ]));
    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    $this->actingAs($owner)->post(route('businesses.daily.post', [$business, $entry]));

    $balances = app(BalanceService::class);

    // 630,000 less the 5,000 that cleared without cash.
    expect($balances->asAt($business, AccountCode::MarketReceivables)->toDecimal())->toBe('625000.00')
        ->and($balances->trialBalance($business)['balanced'])->toBeTrue();
});

it('reverses a posted day and leaves both records visible', function () {
    [$business, $owner] = tradingBusiness();
    $balances = app(BalanceService::class);

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput());
    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    $this->actingAs($owner)->post(route('businesses.daily.post', [$business, $entry]));

    $this->actingAs($owner)->post(route('businesses.daily.reverse', [$business, $entry]), [
        'reason' => 'Sales figure taken from the wrong POS report.',
    ])->assertRedirect();

    // Back to the opening position exactly.
    expect($balances->asAt($business, AccountCode::Cash)->toDecimal())->toBe('93.00')
        ->and($balances->asAt($business, AccountCode::Stock)->toDecimal())->toBe('1000000.00')
        ->and($balances->trialBalance($business)['balanced'])->toBeTrue()
        ->and($entry->fresh()->transactions()->count())->toBe(14);
});

it('demands a reason to reverse a day', function () {
    [$business, $owner] = tradingBusiness();

    $this->actingAs($owner)->post(route('businesses.daily.store', $business), workedDayInput());
    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    $this->actingAs($owner)->post(route('businesses.daily.post', [$business, $entry]));

    $this->actingAs($owner)
        ->post(route('businesses.daily.reverse', [$business, $entry]), ['reason' => ''])
        ->assertSessionHasErrors('reason');
});

it('refuses to record activity before the opening balance is finalized', function () {
    $business = setupBusiness();
    $owner = App\Models\User::factory()->create();
    $business->members()->attach($owner->id, ['role' => BusinessRole::Owner->value, 'is_active' => true]);

    $this->actingAs($owner)
        ->post(route('businesses.daily.store', $business), workedDayInput())
        ->assertForbidden();
});

it('stops an operator reversing a day', function () {
    [$business] = tradingBusiness();
    $operator = App\Models\User::factory()->create();
    $business->members()->attach($operator->id, ['role' => BusinessRole::Operator->value, 'is_active' => true]);

    $this->actingAs($operator)->post(route('businesses.daily.store', $business), workedDayInput());
    $entry = DailyEntry::forBusiness($business)->firstOrFail();
    $this->actingAs($operator)->post(route('businesses.daily.post', [$business, $entry]));

    $this->actingAs($operator)
        ->post(route('businesses.daily.reverse', [$business, $entry]), ['reason' => 'Changed my mind about it.'])
        ->assertForbidden();
});
