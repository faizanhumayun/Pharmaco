<?php

use App\Domain\Ledger\BalanceService;
use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingLine;
use App\Enums\AccountCode;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\LedgerException;

it('cancels a posting exactly, leaving the balances where they started', function () {
    [$business, $user] = ledgerBusiness();
    $balances = app(BalanceService::class);

    $original = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '5000.00'),
        PostingLine::credit(AccountCode::Sales, '5000.00'),
    ], TransactionType::SaleCash, reason: null);

    app(LedgerPoster::class)->reverse($original, 'entered twice', $user);

    expect($balances->asAt($business, AccountCode::Cash)->isZero())->toBeTrue()
        ->and($balances->asAt($business, AccountCode::Sales)->isZero())->toBeTrue();
});

it('mirrors every line rather than deleting the original ones', function () {
    [$business, $user] = ledgerBusiness();

    $original = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '5000.00'),
        PostingLine::credit(AccountCode::Sales, '5000.00'),
    ], TransactionType::SaleCash, reason: null);

    $reversal = app(LedgerPoster::class)->reverse($original, 'wrong customer', $user);

    // Both sets of entries survive. A correction the reader cannot see is
    // indistinguishable from history being quietly rewritten.
    expect($original->fresh()->lines)->toHaveCount(2)
        ->and($reversal->lines)->toHaveCount(2)
        ->and($reversal->lines->firstWhere('account.code', AccountCode::Cash->value)->credit->toDecimal())
            ->toBe('5000.00');
});

it('links the original and the reversal in both directions', function () {
    [$business, $user] = ledgerBusiness();

    $original = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, reason: null);

    $reversal = app(LedgerPoster::class)->reverse($original, 'duplicate', $user);

    expect($reversal->reversal_of_id)->toBe($original->id)
        ->and($original->fresh()->reversed_by_id)->toBe($reversal->id)
        ->and($original->fresh()->status)->toBe(TransactionStatus::Reversed)
        ->and($reversal->type)->toBe(TransactionType::Reversal)
        ->and($reversal->correction_reason)->toBe('duplicate');
});

it('keeps a reversed transaction counting, because the mirror cancels it', function () {
    [$business, $user] = ledgerBusiness();
    $balances = app(BalanceService::class);

    $original = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, reason: null);

    app(LedgerPoster::class)->reverse($original, 'duplicate', $user);

    // Hiding the original instead would double-count the correction.
    expect($balances->trialBalance($business)['balanced'])->toBeTrue()
        ->and($balances->asAt($business, AccountCode::Cash)->isZero())->toBeTrue();
});

it('refuses a reversal with no reason', function () {
    [$business, $user] = ledgerBusiness();

    $original = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, reason: null);

    expect(fn () => app(LedgerPoster::class)->reverse($original, '   ', $user))
        ->toThrow(LedgerException::class);
});

it('refuses to reverse the same transaction twice', function () {
    [$business, $user] = ledgerBusiness();

    $original = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, reason: null);

    app(LedgerPoster::class)->reverse($original, 'first', $user);

    expect(fn () => app(LedgerPoster::class)->reverse($original->fresh(), 'second', $user))
        ->toThrow(LedgerException::class);
});

it('reverses onto the original day while that day is still open', function () {
    [$business, $user] = ledgerBusiness();

    $original = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, date: '2026-09-02', reason: null);

    $reversal = app(LedgerPoster::class)->reverse($original, 'mistake', $user);

    expect($reversal->business_date->toDateString())->toBe('2026-09-02');
});

it('moves the reversal into the open period once the original day is closed', function () {
    [$business, $user] = ledgerBusiness();

    $original = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, date: '2026-09-02', reason: null);

    $business->forceFill(['locked_through_date' => '2026-09-05'])->save();

    $reversal = app(LedgerPoster::class)->reverse($original, 'found later', $user);

    // Prior closed days keep the figures that were reported at the time; the
    // correction lands where it was discovered, carrying the original date.
    expect($reversal->business_date->greaterThan('2026-09-05'))->toBeTrue()
        ->and($reversal->original_business_date->toDateString())->toBe('2026-09-02')
        ->and($reversal->wasPostedLate())->toBeTrue();
});

it('refuses to reverse a draft, which has no ledger effect to undo', function () {
    [$business, $user] = ledgerBusiness();

    $original = postEntry($business, $user, [
        PostingLine::debit(AccountCode::Cash, '100.00'),
        PostingLine::credit(AccountCode::Sales, '100.00'),
    ], TransactionType::SaleCash, reason: null);

    $original->forceFill(['status' => TransactionStatus::Draft])->saveQuietly();

    expect(fn () => app(LedgerPoster::class)->reverse($original->fresh(), 'why not', $user))
        ->toThrow(LedgerException::class);
});
