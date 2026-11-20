<?php

namespace App\Domain\Opening\Actions;

use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingSpec;
use App\Domain\Opening\OpeningBalanceCalculator;
use App\Enums\BusinessStatus;
use App\Enums\OpeningBalanceStatus;
use App\Enums\TransactionType;
use App\Exceptions\LedgerException;
use App\Models\OpeningBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns a reviewed draft into the business's starting point.
 *
 * Irreversible by design: there is no un-finalize route. Everything the system
 * will ever report is opening plus transactions, so this is the moment the
 * arithmetic acquires its origin.
 */
class FinalizeOpeningBalance
{
    public function __construct(
        private readonly OpeningBalanceCalculator $calculator,
        private readonly LedgerPoster $poster,
        private readonly \App\Domain\Ledger\ChartOfAccounts $chart,
    ) {}

    public function handle(OpeningBalance $opening, User $by, string $confirmation): OpeningBalance
    {
        if (! $opening->isEditable()) {
            throw new LedgerException('This opening balance has already been finalized.');
        }

        $position = $this->calculator->fromRecord($opening);

        if ($position->isEmpty()) {
            throw new LedgerException('An opening balance of nothing cannot be finalized.');
        }

        if ($position->needsExplanation() && trim((string) $opening->notes) === '') {
            throw new LedgerException(
                'The balancing figure is large relative to the assets declared. '
                . 'Explain it before finalizing, or revise the figures.'
            );
        }

        $business = $opening->business;

        return DB::transaction(function () use ($opening, $business, $position, $by, $confirmation) {
            $transaction = $this->poster->post(new PostingSpec(
                business: $business,
                date: $opening->opening_date,
                type: TransactionType::Opening,
                lines: $this->placed($business, $position->toPostingLines()),
                createdBy: $by,
                narration: 'Opening financial position',
                source: $opening,
            ));

            $opening->forceFill([
                'status' => OpeningBalanceStatus::Finalized,
                'transaction_id' => $transaction->id,
                'finalized_by' => $by->id,
                'finalized_at' => now(),
                'total_assets' => $position->assets->toDecimal(),
                'total_liabilities' => $position->liabilities->toDecimal(),
                'net_position' => $position->netPosition()->toDecimal(),
                'balancing_figure' => $position->balancingFigure()->toDecimal(),
                'confirmation_text' => $confirmation,
            ])->save();

            // Activation is earned here, not granted by an admin: from this
            // point transactions have a starting point to build on.
            $business->forceFill([
                'opening_date' => $opening->opening_date->toDateString(),
                'status' => BusinessStatus::Active,
            ])->save();

            activity()
                ->performedOn($opening)
                ->causedBy($by)
                ->withProperties([
                    'lines' => $opening->lines->mapWithKeys(
                        fn ($l) => [$l->account->code => $l->amount->toDecimal()]
                    )->all(),
                    'net_position' => $position->netPosition()->toDecimal(),
                    'balancing_figure' => $position->balancingFigure()->toDecimal(),
                    'confirmation' => $confirmation,
                ])
                ->event('opening_balance.finalized')
                ->log('Opening balance finalized');

            return $opening->fresh(['lines.account', 'transaction.lines.account']);
        });
    }

    /**
     * Puts each opening figure where it can be posted.
     *
     * The opening balance states totals — what the market owes, what is owed
     * to companies. A business moved in from an old system already has its
     * customers and companies, each with an account under those totals, and a
     * total cannot be posted to an account that has accounts beneath it. So it
     * goes to that account's "Unallocated", exactly where a day's summary
     * goes, and from there each balance is moved to the name it belongs to.
     *
     * @param  array<int, \App\Domain\Ledger\PostingLine>  $lines
     * @return array<int, \App\Domain\Ledger\PostingLine>
     */
    private function placed(\App\Models\Business $business, array $lines): array
    {
        return array_map(function (\App\Domain\Ledger\PostingLine $line) use ($business) {
            if (! $line->account instanceof \App\Enums\AccountCode) {
                return $line;
            }

            $code = $this->chart->postingCodeFor($business, $line->account);

            if ($code === $line->account->value) {
                return $line;
            }

            return $line->debit->isZero()
                ? \App\Domain\Ledger\PostingLine::credit($code, $line->credit, $line->memo)
                : \App\Domain\Ledger\PostingLine::debit($code, $line->debit, $line->memo);
        }, $lines);
    }
}
