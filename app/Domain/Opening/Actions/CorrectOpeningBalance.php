<?php

namespace App\Domain\Opening\Actions;

use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\PostingSpec;
use App\Enums\AccountCode;
use App\Enums\TransactionType;
use App\Exceptions\LedgerException;
use App\Models\OpeningBalance;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;

/**
 * Corrects a finalized opening balance without editing it.
 *
 * The correction lands in the current open period, which is right: prior closed
 * days keep the figures that were reported at the time, and the change is
 * explained rather than erased.
 */
class CorrectOpeningBalance
{
    public function __construct(private readonly LedgerPoster $poster) {}

    public function handle(
        OpeningBalance $opening,
        AccountCode $account,
        Money $amount,
        string $reason,
        User $by,
    ): Transaction {
        if ($opening->isEditable()) {
            throw new LedgerException(
                'This opening balance is still a draft. Correct the figures directly instead.'
            );
        }

        if ($amount->isZero()) {
            throw new LedgerException('A correction of zero changes nothing.');
        }

        if (trim($reason) === '') {
            throw new LedgerException('An opening balance correction must say why.');
        }

        $business = $opening->business;
        $size = $amount->absolute();

        /*
         * `amount` is the signed change to the account's *natural* balance:
         * +200,000 on stock means 200,000 more stock; −200,000 on payables
         * means 200,000 less owed.
         *
         * The account takes a debit when raising a debit-normal balance or
         * lowering a credit-normal one — the two cases where "more of what this
         * account is for" and "debit" line up.
         */
        $debitTheAccount = $amount->isPositive() === $account->type()->increasesOnDebit();

        // Once a control account has sub-accounts it stops taking postings of
        // its own, so a correction goes where the day-to-day summary figures
        // go: the account's own "Unallocated" ledger. Without this, a business
        // that has named even one company can never correct what it started
        // out owing.
        $target = $this->postableCode($business, $account);

        // The other side always goes to Opening Balance Equity: a cutover
        // correction restates the starting position, it is not trading.
        $lines = $debitTheAccount
            ? [
                PostingLine::debit($target, $size),
                PostingLine::credit(AccountCode::OpeningBalanceEquity, $size),
            ]
            : [
                PostingLine::credit($target, $size),
                PostingLine::debit(AccountCode::OpeningBalanceEquity, $size),
            ];

        return $this->poster->post(new PostingSpec(
            business: $business,
            date: $this->openPeriodDate($business),
            type: TransactionType::Adjustment,
            lines: $lines,
            createdBy: $by,
            narration: 'Opening balance correction — ' . $account->label(),
            source: $opening,
            reason: $reason,
            originalDate: $opening->opening_date,
        ));
    }

    /**
     * Where the correction may actually post: the account itself, or its
     * "Unallocated" ledger once it has sub-accounts.
     */
    private function postableCode(\App\Models\Business $business, AccountCode $account): string
    {
        $ledger = \App\Models\Account::query()->forBusiness($business)->code($account)->firstOrFail();

        if ($ledger->is_postable) {
            return $account->value;
        }

        $unallocated = \App\Models\Account::query()->forBusiness($business)
            ->where('code', $account->value . '-000')
            ->first();

        if ($unallocated === null || ! $unallocated->is_postable) {
            throw new LedgerException(
                $account->label() . ' is split across sub-accounts with no unallocated ledger to correct into.'
            );
        }

        return $unallocated->code;
    }

    /**
     * The earliest day a correction to the starting position can still land.
     *
     * It restates where the business began, so it belongs as close to the
     * opening date as the locks allow: the day after opening, or the day after
     * the last close if that is later. Dated "today" instead, every day between
     * would carry the uncorrected start — a drawer expected to hold less than
     * it did, and a closing screen that could never agree with the count.
     */
    private function openPeriodDate(\App\Models\Business $business): \Illuminate\Support\Carbon
    {
        $today = $business->today();
        $afterOpening = $business->opening_date->copy()->addDay();
        $afterLock = $business->locked_through_date?->copy()->addDay();

        $date = $afterLock !== null && $afterLock->greaterThan($afterOpening) ? $afterLock : $afterOpening;

        // A business that opened today has no later day yet.
        return $date->greaterThan($today) ? $today : $date;
    }
}
