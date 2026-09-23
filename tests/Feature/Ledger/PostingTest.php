<?php

use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\PostingSpec;
use App\Enums\AccountCode;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\AccountNotPostableException;
use App\Exceptions\ClosedPeriodException;
use App\Exceptions\LedgerException;
use App\Exceptions\UnbalancedTransactionException;
use App\Models\LedgerEntry;

it('posts a balanced transaction and writes both sides', function () {
    [$business, $user] = ledgerBusiness();

    $transaction = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Stock, '129000.00'),
        PostingLine::credit(AccountCode::CompanyPayables, '129000.00'),
    ], TransactionType::PurchaseCredit, reason: null);

    expect($transaction->status)->toBe(TransactionStatus::Posted)
        ->and($transaction->lines)->toHaveCount(2)
        ->and($transaction->amount->toDecimal())->toBe('129000.00')
        ->and($transaction->posted_at)->not->toBeNull();
});

it('refuses a transaction whose debits and credits differ', function () {
    [$business, $user] = ledgerBusiness();

    expect(fn () => postEntry($business, $user, [
        PostingLine::debit(AccountCode::Stock, '100.00'),
        PostingLine::credit(AccountCode::CompanyPayables, '99.99'),
    ]))->toThrow(UnbalancedTransactionException::class);
});

it('writes nothing at all when a posting is refused', function () {
    [$business, $user] = ledgerBusiness();

    try {
        postEntry($business, $user, [
            PostingLine::debit(AccountCode::Stock, '100.00'),
            PostingLine::credit(AccountCode::CompanyPayables, '90.00'),
        ]);
    } catch (LedgerException) {
        // expected
    }

    // A partial posting is worse than no posting: it would leave the business
    // permanently out of balance with nothing to point at.
    expect(LedgerEntry::acrossAllBusinesses()->count())->toBe(0)
        ->and(App\Models\Transaction::acrossAllBusinesses()->count())->toBe(0);
});

it('refuses a transaction for zero', function () {
    [$business, $user] = ledgerBusiness();

    expect(fn () => postEntry($business, $user, [
        PostingLine::debit(AccountCode::Stock, '0.00'),
    ]))->toThrow(InvalidArgumentException::class);
});

it('refuses a transaction with no lines', function () {
    [$business, $user] = ledgerBusiness();

    expect(fn () => postEntry($business, $user, []))->toThrow(LedgerException::class);
});

it('refuses a line that is both a debit and a credit', function () {
    expect(fn () => new ReflectionClass(PostingLine::class))->not->toThrow(Exception::class);

    expect(fn () => PostingLine::debit(AccountCode::Cash, '-5.00'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to post into a closed period', function () {
    [$business, $user] = ledgerBusiness();

    $business->forceFill(['locked_through_date' => now()->addDay()->toDateString()])->save();

    expect(fn () => postEntry($business->fresh(), $user, [
        PostingLine::debit(AccountCode::Stock, '100.00'),
        PostingLine::credit(AccountCode::CompanyPayables, '100.00'),
    ]))->toThrow(ClosedPeriodException::class);
});

it('refuses to post to the bank account, which is seeded but not in use', function () {
    [$business, $user] = ledgerBusiness();

    expect(fn () => postEntry($business, $user, [
        PostingLine::debit(AccountCode::Bank, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ]))->toThrow(AccountNotPostableException::class);
});

it('refuses to post directly to a control account once it has sub-accounts', function () {
    [$business, $user] = ledgerBusiness();

    app(App\Domain\Ledger\ChartOfAccounts::class)
        ->addSubAccount($business, AccountCode::CompanyPayables, '001', 'Getz Pharma');

    expect(fn () => postEntry($business, $user, [
        PostingLine::debit(AccountCode::Stock, '100.00'),
        PostingLine::credit(AccountCode::CompanyPayables, '100.00'),
    ]))->toThrow(AccountNotPostableException::class);

    // …but the child accepts it, and the parent's balance follows from it.
    $transaction = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Stock, '100.00'),
        PostingLine::credit('2000-001', '100.00'),
    ]);

    expect($transaction->lines)->toHaveCount(2);
});

it('refuses an account that belongs to no business of ours', function () {
    [$business, $user] = ledgerBusiness();

    expect(fn () => postEntry($business, $user, [
        PostingLine::debit('9999', '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ]))->toThrow(LedgerException::class);
});

it('requires a reason for an adjustment or a reversal', function () {
    [$business, $user] = ledgerBusiness();

    expect(fn () => postEntry($business, $user, [
        PostingLine::debit(AccountCode::Stock, '100.00'),
        PostingLine::credit(AccountCode::OpeningBalanceEquity, '100.00'),
    ], TransactionType::Adjustment, reason: '  '))->toThrow(LedgerException::class);
});

it('records the business date separately from the system timestamp', function () {
    [$business, $user] = ledgerBusiness();

    $transaction = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, date: '2026-08-15', reason: null);

    expect($transaction->business_date->toDateString())->toBe('2026-08-15')
        ->and($transaction->created_at->toDateString())->not->toBe('2026-08-15');
});

it('stamps every line with the same business and date as its transaction', function () {
    [$business, $user] = ledgerBusiness();

    $transaction = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, date: '2026-08-15', reason: null);

    foreach ($transaction->lines as $line) {
        expect($line->business_id)->toBe($business->id)
            ->and($line->business_date->toDateString())->toBe('2026-08-15');
    }
});
