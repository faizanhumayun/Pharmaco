<?php

use App\Domain\Ledger\BalanceService;
use App\Domain\Ledger\PostingLine;
use App\Enums\AccountCode;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Support\CurrentBusiness;

/*
| Every phase that adds a business-scoped table adds cases here. These three
| are the ledger's.
*/

it('keeps one business\'s ledger invisible to another', function () {
    [$first, $firstUser] = ledgerBusiness();
    [$second] = ledgerBusiness();

    postEntry($first, $firstUser, [
        PostingLine::debit(AccountCode::Cash, '5000.00'),
        PostingLine::credit(AccountCode::Sales, '5000.00'),
    ], TransactionType::SaleCash, reason: null);

    app(CurrentBusiness::class)->set($second);

    expect(Transaction::count())->toBe(0)
        ->and(LedgerEntry::count())->toBe(0)
        ->and(Account::count())->toBe(count(AccountCode::cases()));
});

it('keeps balances scoped to their own business', function () {
    [$first, $firstUser] = ledgerBusiness();
    [$second] = ledgerBusiness();
    $balances = app(BalanceService::class);

    postEntry($first, $firstUser, [
        PostingLine::debit(AccountCode::Cash, '5000.00'),
        PostingLine::credit(AccountCode::Sales, '5000.00'),
    ], TransactionType::SaleCash, reason: null);

    expect($balances->asAt($first, AccountCode::Cash)->toDecimal())->toBe('5000.00')
        ->and($balances->asAt($second, AccountCode::Cash)->isZero())->toBeTrue();
});

it('takes business_id as a required argument rather than trusting a global scope', function () {
    [$first, $firstUser] = ledgerBusiness();
    [$second] = ledgerBusiness();

    postEntry($first, $firstUser, [
        PostingLine::debit(AccountCode::Cash, '5000.00'),
        PostingLine::credit(AccountCode::Sales, '5000.00'),
    ], TransactionType::SaleCash, reason: null);

    // The balance service aggregates with the query builder, which no global
    // scope reaches. Setting the wrong context must not change the answer.
    app(CurrentBusiness::class)->set($second);

    expect(app(BalanceService::class)->asAt($first, AccountCode::Cash)->toDecimal())
        ->toBe('5000.00');
});

it('never lets a posting reach another business\'s account', function () {
    [$first, $firstUser] = ledgerBusiness();
    [$second] = ledgerBusiness();

    postEntry($first, $firstUser, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, reason: null);

    $secondAccountIds = Account::forBusiness($second)->pluck('id');

    expect(
        LedgerEntry::acrossAllBusinesses()->whereIn('account_id', $secondAccountIds)->count()
    )->toBe(0);
});
