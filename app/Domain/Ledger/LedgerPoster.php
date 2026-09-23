<?php

namespace App\Domain\Ledger;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\AccountNotPostableException;
use App\Exceptions\ClosedPeriodException;
use App\Exceptions\LedgerException;
use App\Exceptions\UnbalancedTransactionException;
use App\Models\Account;
use App\Models\Business;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only class in the codebase that writes ledger entries.
 *
 * That is the enforcement mechanism for every accounting invariant in this
 * system. If one class is the sole writer, its tests are the guarantee; if five
 * classes write, there is no guarantee. Everything else asks this class.
 */
class LedgerPoster
{
    public function post(PostingSpec $spec): Transaction
    {
        $this->assertBalanced($spec);
        $this->assertPeriodOpen($spec->business, $spec->date);
        $this->assertReasonGiven($spec);

        $accounts = $this->resolveAccounts($spec);

        return DB::transaction(function () use ($spec, $accounts) {
            $transaction = Transaction::create([
                'business_id' => $spec->business->id,
                'business_date' => $spec->date->toDateString(),
                'type' => $spec->type,
                'reference_no' => $spec->reference,
                'narration' => $spec->narration,
                'amount' => $spec->headlineAmount()->toDecimal(),
                'source_type' => $spec->source?->getMorphClass(),
                'source_id' => $spec->source?->getKey(),
                'status' => TransactionStatus::Posted,
                'correction_reason' => $spec->reason,
                'original_business_date' => $spec->originalDate?->toDateString(),
                'created_by' => $spec->createdBy->id,
                'posted_at' => now(),
            ]);

            foreach ($spec->lines as $line) {
                LedgerEntry::create([
                    'business_id' => $spec->business->id,
                    'transaction_id' => $transaction->id,
                    'account_id' => $accounts[$line->accountCode()]->id,
                    'business_date' => $spec->date->toDateString(),
                    'debit' => $line->debit->toDecimal(),
                    'credit' => $line->credit->toDecimal(),
                    'memo' => $line->memo,
                ]);
            }

            return $transaction->load('lines.account');
        });
    }

    /**
     * Reverses a posted transaction by posting its mirror.
     *
     * The original is never edited or hidden — both stay visible and linked in
     * both directions, because a correction the reader cannot see is
     * indistinguishable from history being quietly rewritten.
     */
    public function reverse(Transaction $original, string $reason, User $by, ?Carbon $on = null): Transaction
    {
        if ($original->status === TransactionStatus::Draft) {
            throw new LedgerException('A draft transaction has no ledger effect to reverse.');
        }

        if ($original->isReversed()) {
            throw new LedgerException('This transaction has already been reversed.');
        }

        if (trim($reason) === '') {
            throw new LedgerException('A reversal must say why.');
        }

        $business = $original->business;

        // Reverse on the original's own day where that day is still open, so
        // the correction lands in the period it belongs to. Once the day is
        // closed, it lands in the open period instead and the original date is
        // recorded — prior closed days keep the figures that were reported.
        $date = $on ?? $original->business_date->copy();

        if ($business->isDayClosed($date)) {
            $date = $this->firstOpenDay($business);
        }

        $mirrored = array_map(
            fn (LedgerEntry $line) => $line->isDebit()
                ? PostingLine::credit($line->account->code, $line->debit, $line->memo)
                : PostingLine::debit($line->account->code, $line->credit, $line->memo),
            $original->lines()->with('account')->get()->all()
        );

        return DB::transaction(function () use ($original, $mirrored, $business, $date, $reason, $by) {
            $reversal = $this->post(new PostingSpec(
                business: $business,
                date: $date,
                type: TransactionType::Reversal,
                lines: $mirrored,
                createdBy: $by,
                narration: 'Reversal of #' . $original->id . ' — ' . $original->type->label(),
                reference: $original->reference_no,
                source: $original->source,
                reason: $reason,
                originalDate: $original->business_date->copy(),
            ));

            $reversal->forceFill(['reversal_of_id' => $original->id])->saveQuietly();

            // The one write a posted transaction still accepts.
            $original->forceFill([
                'reversed_by_id' => $reversal->id,
                'status' => TransactionStatus::Reversed,
            ])->save();

            return $reversal;
        });
    }

    /**
     * The earliest day a posting is still allowed on.
     *
     * Normally today, but never a day that is itself closed — otherwise a
     * correction would be refused by the very guard that sent it here. Public
     * so that anything posting "now" asks here rather than assuming today is
     * open: once today has been closed, today is not allowed.
     */
    public function firstOpenDay(Business $business): Carbon
    {
        $today = $business->today();
        $dayAfterLock = $business->locked_through_date?->copy()->addDay();

        return $dayAfterLock !== null && $dayAfterLock->greaterThan($today)
            ? $dayAfterLock
            : $today;
    }

    private function assertBalanced(PostingSpec $spec): void
    {
        if ($spec->lines === []) {
            throw new LedgerException('A transaction must have at least one line.');
        }

        $debits = $spec->totalDebits();
        $credits = $spec->totalCredits();

        if (! $debits->equals($credits)) {
            throw UnbalancedTransactionException::make($debits, $credits);
        }

        if ($debits->isZero()) {
            throw new LedgerException('A transaction cannot be for zero.');
        }
    }

    private function assertPeriodOpen(Business $business, Carbon $date): void
    {
        if ($business->isDayClosed($date)) {
            throw ClosedPeriodException::make($date, $business->locked_through_date);
        }
    }

    private function assertReasonGiven(PostingSpec $spec): void
    {
        if ($spec->type->requiresReason() && trim((string) $spec->reason) === '') {
            throw new LedgerException(
                "A {$spec->type->label()} must record why it was made."
            );
        }
    }

    /**
     * Resolves account codes to this business's own accounts.
     *
     * Ids differ per business, so codes are resolved within the business every
     * time rather than cached or assumed.
     *
     * @return array<string, Account>
     */
    private function resolveAccounts(PostingSpec $spec): array
    {
        $codes = array_unique(array_map(fn (PostingLine $l) => $l->accountCode(), $spec->lines));

        $accounts = Account::query()
            ->forBusiness($spec->business)
            ->whereIn('code', $codes)
            ->with('children')
            ->get()
            ->keyBy('code');

        foreach ($codes as $code) {
            $account = $accounts->get($code);

            if ($account === null) {
                throw new LedgerException(
                    "Account {$code} does not exist for {$spec->business->name}."
                );
            }

            /*
             * `is_postable` is the gate, not "has children". A control account
             * is closed to direct postings at the moment it gains children, and
             * the one legitimate exception is the migration posting that moves
             * its balance onto the new Unallocated child — which necessarily
             * runs while the child already exists. `ledger:verify` still fails
             * any account left postable with children, so the invariant holds
             * outside that single transaction.
             */
            if (! $account->is_postable) {
                throw AccountNotPostableException::make($account, $account->notPostableReason());
            }
        }

        return $accounts->all();
    }
}
