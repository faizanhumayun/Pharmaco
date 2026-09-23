<?php

use App\Domain\Ledger\BalanceService;
use App\Domain\Ledger\PostingLine;
use App\Enums\AccountCode;
use App\Enums\TransactionType;

/*
| The specification's own worked day, posted through the real ledger:
| purchases 150,000 on credit less 21,000 trade discount, sales 300,000
| (120,000 cash / 180,000 credit) at 30,000 gross profit, 50,000 collected,
| 150,000 paid to companies, 5,000 expenses.
*/
function postWorkedDay(): array
{
    [$business, $user] = ledgerBusiness();

    // Opening position from the specification.
    postEntry($business, $user, [
        PostingLine::debit(AccountCode::Stock, '1000000.00'),
        PostingLine::debit(AccountCode::Cash, '93.00'),
        PostingLine::debit(AccountCode::MarketReceivables, '500000.00'),
        PostingLine::debit(AccountCode::OpeningBalanceEquity, '1699907.00'),
        PostingLine::credit(AccountCode::CompanyPayables, '3200000.00'),
    ], TransactionType::Opening, date: '2026-09-01', reason: null);

    $day = '2026-09-02';

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::Stock, '129000.00'),
        PostingLine::credit(AccountCode::CompanyPayables, '129000.00'),
    ], TransactionType::PurchaseCredit, date: $day, reason: null);

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '120000.00'),
        PostingLine::credit(AccountCode::Sales, '120000.00'),
    ], TransactionType::SaleCash, date: $day, reason: null);

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::MarketReceivables, '180000.00'),
        PostingLine::credit(AccountCode::Sales, '180000.00'),
    ], TransactionType::SaleCredit, date: $day, reason: null);

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::CostOfGoodsSold, '270000.00'),
        PostingLine::credit(AccountCode::Stock, '270000.00'),
    ], TransactionType::CostOfGoodsSold, date: $day, reason: null);

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '50000.00'),
        PostingLine::credit(AccountCode::MarketReceivables, '50000.00'),
    ], TransactionType::Collection, date: $day, reason: null);

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::CompanyPayables, '150000.00'),
        PostingLine::credit(AccountCode::Cash, '150000.00'),
    ], TransactionType::CompanyPayment, date: $day, reason: null);

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::OperatingExpenses, '5000.00'),
        PostingLine::credit(AccountCode::Cash, '5000.00'),
    ], TransactionType::Expense, date: $day, reason: null);

    return [$business, $user, $day];
}

it('derives every headline balance from the ledger and matches the hand calculation', function () {
    [$business, , $day] = postWorkedDay();
    $balances = app(BalanceService::class);

    expect($balances->asAt($business, AccountCode::Cash, $day)->toDecimal())->toBe('15093.00')
        ->and($balances->asAt($business, AccountCode::MarketReceivables, $day)->toDecimal())->toBe('630000.00')
        ->and($balances->asAt($business, AccountCode::CompanyPayables, $day)->toDecimal())->toBe('3179000.00')
        ->and($balances->asAt($business, AccountCode::Stock, $day)->toDecimal())->toBe('859000.00');
});

it('presents liabilities as positive rather than as raw negative sums', function () {
    [$business, , $day] = postWorkedDay();

    // A payable sums to −3,179,000 in raw debit-minus-credit terms. Nobody
    // wants to read it that way, and a sign error here would flip net position.
    expect(app(BalanceService::class)->asAt($business, AccountCode::CompanyPayables, $day)->isPositive())
        ->toBeTrue();
});

it('computes the movement of an account between two dates', function () {
    [$business, , $day] = postWorkedDay();
    $balances = app(BalanceService::class);

    $receivableDelta = $balances->movement($business, AccountCode::MarketReceivables, $day, $day);

    // Credit sales 180,000 less collections 50,000. The figure the owner needs
    // as much as daily profit.
    expect($receivableDelta->toDecimal())->toBe('130000.00');
});

it('derives the movement two ways and gets the same answer', function () {
    [$business, , $day] = postWorkedDay();
    $balances = app(BalanceService::class);

    $byMovement = $balances->movement($business, AccountCode::MarketReceivables, $day, $day);
    $byDifference = $balances->asAt($business, AccountCode::MarketReceivables, $day)
        ->minus($balances->asAt($business, AccountCode::MarketReceivables, '2026-09-01'));

    // Redundancy on purpose: computed both ways, they must agree. It costs one
    // query and continuously proves collections, sales and returns all post.
    expect($byMovement->equals($byDifference))->toBeTrue();
});

it('reports the management position with both derivations agreeing', function () {
    [$business, , $day] = postWorkedDay();

    $position = app(BalanceService::class)->position($business, $day);

    expect($position->assets->toDecimal())->toBe('1504093.00')
        ->and($position->liabilities->toDecimal())->toBe('3179000.00')
        ->and($position->netPosition()->toDecimal())->toBe('-1674907.00')
        ->and($position->retainedProfit()->toDecimal())->toBe('25000.00')
        ->and($position->isConsistent())->toBeTrue()
        ->and($position->discrepancy()->isZero())->toBeTrue();
});

it('computes gross and net profit as separate figures', function () {
    [$business, , $day] = postWorkedDay();
    $balances = app(BalanceService::class);

    $sales = $balances->asAt($business, AccountCode::Sales, $day);
    $cogs = $balances->asAt($business, AccountCode::CostOfGoodsSold, $day);
    $expenses = $balances->asAt($business, AccountCode::OperatingExpenses, $day);

    $gross = $sales->minus($cogs);
    $net = $gross->minus($expenses);

    // Two labelled figures, never one number called "profit".
    expect($gross->toDecimal())->toBe('30000.00')
        ->and($net->toDecimal())->toBe('25000.00');
});

it('balances the trial balance across the whole business', function () {
    [$business] = postWorkedDay();

    $trial = app(BalanceService::class)->trialBalance($business);

    expect($trial['balanced'])->toBeTrue()
        ->and($trial['difference']->isZero())->toBeTrue();
});

it('reports every account including those with no activity', function () {
    [$business, , $day] = postWorkedDay();

    $all = app(BalanceService::class)->all($business, $day);

    // A missing key is how a dashboard renders a blank instead of a nought.
    expect($all)->toHaveCount(count(AccountCode::cases()))
        ->and($all->get(AccountCode::BadDebts->value)->isZero())->toBeTrue();
});

it('respects the as-at date and excludes later activity', function () {
    [$business] = postWorkedDay();
    $balances = app(BalanceService::class);

    expect($balances->asAt($business, AccountCode::Cash, '2026-09-01')->toDecimal())->toBe('93.00')
        ->and($balances->asAt($business, AccountCode::Cash, '2026-09-02')->toDecimal())->toBe('15093.00');
});

it('excludes draft transactions from every figure', function () {
    [$business, $user, $day] = postWorkedDay();
    $balances = app(BalanceService::class);

    $before = $balances->asAt($business, AccountCode::Cash, $day);

    $transaction = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '999999.00'),
        PostingLine::credit(AccountCode::Sales, '999999.00'),
    ], TransactionType::SaleCash, date: $day, reason: null);

    $transaction->forceFill(['status' => App\Enums\TransactionStatus::Draft])->saveQuietly();

    expect($balances->asAt($business, AccountCode::Cash, $day)->equals($before))->toBeTrue();
});

it('reports a control account as reconciling when it has no children', function () {
    [$business] = postWorkedDay();

    expect(app(BalanceService::class)->controlReconciles($business, AccountCode::CompanyPayables))
        ->toBeTrue();
});

it('keeps a control account equal to the sum of its children by construction', function () {
    [$business, $user] = ledgerBusiness();
    $chart = app(App\Domain\Ledger\ChartOfAccounts::class);

    $chart->addSubAccount($business, AccountCode::CompanyPayables, '001', 'Getz Pharma');
    $chart->addSubAccount($business, AccountCode::CompanyPayables, '002', 'Abbott');

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::Stock, '800000.00'),
        PostingLine::credit('2000-001', '800000.00'),
    ], TransactionType::PurchaseCredit, reason: null);

    postEntry($business, $user, [
        PostingLine::debit(AccountCode::Stock, '1200000.00'),
        PostingLine::credit('2000-002', '1200000.00'),
    ], TransactionType::PurchaseCredit, reason: null);

    $balances = app(BalanceService::class);

    // "Total company payable = sum of company ledgers" is structurally true,
    // not maintained. This test proves it rather than making it so.
    expect($balances->asAt($business, AccountCode::CompanyPayables)->toDecimal())->toBe('2000000.00')
        ->and($balances->controlReconciles($business, AccountCode::CompanyPayables))->toBeTrue();
});
