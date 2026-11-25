<?php

use App\Domain\Ledger\BalanceService;
use App\Enums\AccountCode;
use App\Enums\BusinessRole;
use App\Domain\Daily\Actions\PostDailyEntry;
use App\Domain\Daily\Actions\SaveDailyEntry;
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

/**
 * A day saved straight through the engine, the way DayWriter saves one: the
 * hand-entered form is gone, the rules that put a day in the books are not.
 */
function saveDay($business, $by, array $overrides = []): DailyEntry
{
    $data = array_replace(
        array_fill_keys(DailyEntry::MONEY_FIELDS, '0'),
        ['purchases' => [], 'sales' => [], 'collections' => [], 'returns' => [], 'expenses' => []],
        workedDayInput($overrides),
    );

    return app(SaveDailyEntry::class)->handle($business, $data, $by);
}

/** The same, posted to the ledger. */
function postDay($business, $by, array $overrides = []): DailyEntry
{
    return app(PostDailyEntry::class)->handle(saveDay($business, $by, $overrides), $by);
}

it('saves a day as a draft with no ledger effect', function () {
    [$business, $owner] = tradingBusiness();
    $before = Transaction::forBusiness($business)->count();

    $entry = saveDay($business, $owner);

    expect($entry->status)->toBe(DocumentStatus::Draft)
        ->and(Transaction::forBusiness($business)->count())->toBe($before);
});

it('computes totals rather than accepting them', function () {
    [$business, $owner] = tradingBusiness();

    $entry = saveDay($business, $owner);

    expect($entry->totalPurchases()->toDecimal())->toBe('150000.00')
        ->and($entry->costAddedToStock()->toDecimal())->toBe('129000.00')
        ->and($entry->totalSales()->toDecimal())->toBe('300000.00')
        ->and($entry->costOfGoodsSold()->toDecimal())->toBe('270000.00')
        ->and($entry->netProfit()->toDecimal())->toBe('25000.00')
        ->and(round($entry->marginPercent(), 2))->toBe(10.0);
});

it('posts the worked day to exactly the figures the design predicted', function () {
    [$business, $owner] = tradingBusiness();

    postDay($business, $owner);

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

    $entry = postDay($business, $owner);

    // Purchase, two sales, COGS, collection, payment, expense.
    expect($entry->fresh()->transactions)->toHaveCount(7);
});

it('keeps the trade discount out of stock', function () {
    [$business, $owner] = tradingBusiness();

    postDay($business, $owner, [
        'sale_cash' => '0', 'sale_credit' => '0', 'gross_profit' => '0',
        'collection_cash' => '0', 'company_payment_cash' => '0', 'expenses_cash' => '0',
    ]);

    $balances = app(BalanceService::class);

    // 150,000 invoiced less 21,000 discount adds 129,000 of cost, not 150,000.
    expect($balances->asAt($business, AccountCode::Stock)->toDecimal())->toBe('1129000.00')
        ->and($balances->asAt($business, AccountCode::CompanyPayables)->toDecimal())->toBe('3329000.00');
});

it('refuses to edit a posted day', function () {
    [$business, $owner] = tradingBusiness();

    $entry = postDay($business, $owner);

    expect(fn () => $entry->fresh()->update(['sale_cash' => '1.00']))
        ->toThrow(ImmutableRecordException::class);
});

it('records owner drawings against equity rather than profit', function () {
    [$business, $owner] = tradingBusiness();

    $entry = postDay($business, $owner, ['owner_drawing' => '10000']);

    $balances = app(BalanceService::class);

    // Cash falls, but profit is untouched — the most common reason a cash
    // balance stops reconciling is that this has nowhere to go.
    expect($balances->asAt($business, AccountCode::Cash)->toDecimal())->toBe('5093.00')
        ->and($balances->asAt($business, AccountCode::OwnerDrawings)->toDecimal())->toBe('10000.00')
        ->and($entry->fresh()->netProfit()->toDecimal())->toBe('25000.00');
});

it('clears a receivable through discount and write-off without cash', function () {
    [$business, $owner] = tradingBusiness();

    postDay($business, $owner, ['discount_allowed' => '2000', 'bad_debt' => '3000']);

    $balances = app(BalanceService::class);

    // 630,000 less the 5,000 that cleared without cash.
    expect($balances->asAt($business, AccountCode::MarketReceivables)->toDecimal())->toBe('625000.00')
        ->and($balances->trialBalance($business)['balanced'])->toBeTrue();
});

it('shows a day read only, with each section on its own', function () {
    [$business, $owner] = tradingBusiness();
    postDay($business, $owner);

    foreach (['', '?section=sales', '?section=purchases', '?section=collections', '?section=payments', '?section=expenses', '?section=history'] as $section) {
        $this->actingAs($owner)
            ->get(route('businesses.daily.day', [$business, '2026-09-01']).$section)
            ->assertOk()
            ->assertDontSee('Post day')
            ->assertDontSee('Reverse day');
    }

    $this->actingAs($owner)->get(route('businesses.daily.index', $business))->assertOk();
});

it('sends an old link to a day to the day', function () {
    [$business, $owner] = tradingBusiness();
    $entry = postDay($business, $owner);

    $this->actingAs($owner)
        ->get(route('businesses.daily.show', [$business, $entry]))
        ->assertRedirect(route('businesses.daily.day', [$business, '2026-09-01']));
});
