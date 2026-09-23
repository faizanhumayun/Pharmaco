<?php

namespace App\Domain\Daily;

use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\LedgerPoster;
use App\Domain\Ledger\PostingLine;
use App\Domain\Ledger\PostingSpec;
use App\Enums\AccountCode;
use App\Enums\TransactionType;
use App\Models\DailyEntry;
use App\Models\ExpenseLine;
use App\Models\PurchaseLine;
use App\Models\SaleLine;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;

/**
 * Translates one day's typed figures into the ledger.
 *
 * The daily entry form is a capture surface, not a transaction type: one
 * submitted day produces several typed transactions. When invoice-level entry
 * replaces this form, it produces the same types with finer detail and nothing
 * downstream changes.
 */
class DailyEntryPostingService
{
    public function __construct(
        private readonly LedgerPoster $poster,
        private readonly ChartOfAccounts $chart,
    ) {}

    /** @return array<int, Transaction> */
    public function post(DailyEntry $entry, User $by): array
    {
        $posted = [];

        foreach ($this->specs($entry, $by) as $spec) {
            $posted[] = $this->poster->post($spec);
        }

        return $posted;
    }

    /** @return array<int, PostingSpec> */
    public function specs(DailyEntry $entry, User $by): array
    {
        $specs = [];

        /*
         * Every posting a day produces is a simple two-line debit and credit,
         * so the mapping is expressed as exactly that. Zero amounts are skipped
         * before any line is built — a ledger line of nothing is not a line.
         */
        // Once companies have their own ledgers the control account stops
        // taking direct postings, and a day's total — which does not know which
        // company it belongs to — lands in Unallocated instead.
        $payables = $this->chart->postingCodeFor($entry->business, AccountCode::CompanyPayables);

        // The same holds on the selling side once pharmacies have their own
        // ledgers: a day's total does not know which pharmacy it belongs to.
        $receivables = $this->chart->postingCodeFor($entry->business, AccountCode::MarketReceivables);

        // And on the spending side, where the children are expense heads.
        $expenses = $this->chart->postingCodeFor($entry->business, AccountCode::OperatingExpenses);

        $add = function (
            TransactionType $type,
            Money $amount,
            AccountCode|string $debit,
            AccountCode|string $credit,
            ?string $narration = null,
        ) use (&$specs, $entry, $by) {
            if ($amount->isZero()) {
                return;
            }

            $specs[] = new PostingSpec(
                business: $entry->business,
                date: $entry->business_date,
                type: $type,
                lines: [
                    PostingLine::debit($debit, $amount),
                    PostingLine::credit($credit, $amount),
                ],
                createdBy: $by,
                narration: $narration ?? $type->label(),
                source: $entry,
            );
        };

        // --- Purchases -----------------------------------------------------
        /*
         * A purchase adds its net cost to stock; the credit side splits between
         * what was paid at the time and what is still owed. The trade discount
         * reduces the bill, so it reaches neither stock nor the payable.
         *
         * Where the day names its invoices, each one posts against its own
         * company's ledger — which is what makes the per-company statements
         * something other than a single "unallocated" figure.
         */
        foreach ($this->purchaseAllocations($entry, $payables) as $allocation) {
            $add(
                TransactionType::PurchaseCash, $allocation['covered'],
                AccountCode::Stock, AccountCode::Cash,
                'Purchase paid — '.$allocation['label'],
            );

            $add(
                TransactionType::PurchaseCredit, $allocation['pending'],
                AccountCode::Stock, $allocation['account'],
                'Purchase on account — '.$allocation['label'],
            );

            // Paid beyond what the goods cost is not buying — it settles what
            // was already owed, so it comes off the payable rather than stock.
            $add(
                TransactionType::CompanyPayment, $allocation['excess'],
                $allocation['account'], AccountCode::Cash,
                'Paid against earlier bills — '.$allocation['label'],
            );
        }

        $add(TransactionType::PurchaseReturn, $entry->purchase_return,
            $payables, AccountCode::Stock);

        // --- Sales ---------------------------------------------------------
        /*
         * The mirror of purchases. A sale credits revenue; the debit side
         * splits between what came over the counter and what the pharmacy was
         * allowed to owe.
         *
         * Where the day names its pharmacies, each invoice posts against that
         * pharmacy's own ledger — which is what turns "market receivables" from
         * one number into a set of statements you can chase.
         */
        foreach ($this->saleAllocations($entry, $receivables) as $allocation) {
            $add(
                TransactionType::SaleCash, $allocation['covered'],
                AccountCode::Cash, AccountCode::Sales,
                'Cash sale — '.$allocation['label'],
            );

            $add(
                TransactionType::SaleCredit, $allocation['pending'],
                $allocation['account'], AccountCode::Sales,
                'Credit sale — '.$allocation['label'],
            );

            // Taken beyond what today's invoice came to is not a sale — it is
            // recovery of credit given earlier, so it comes off the receivable
            // rather than adding to revenue.
            $add(
                TransactionType::Collection, $allocation['excess'],
                AccountCode::Cash, $allocation['account'],
                'Recovered against earlier credit — '.$allocation['label'],
            );
        }

        $add(TransactionType::SalesReturn, $entry->sales_return,
            AccountCode::SalesReturns, $receivables);

        /*
         * Cost of goods sold, derived from the gross profit the POS reported.
         *
         * Arithmetically exact given a true sales figure and a true margin, and
         * it inherits every ambiguity in either. Stock is therefore the one
         * headline balance carrying accumulating error, which is what the
         * periodic stock verification exists to bound.
         */
        $cogs = $entry->costOfGoodsSold();

        $add(TransactionType::CostOfGoodsSold, $cogs,
            AccountCode::CostOfGoodsSold, AccountCode::Stock,
            'Cost of goods sold — net sales less gross profit');

        // --- Market --------------------------------------------------------
        $add(TransactionType::Collection, $entry->collection_cash,
            AccountCode::Cash, $receivables);

        // A receivable can clear without cash. Without these two the balance
        // never comes down and the account carries a stub forever.
        $add(TransactionType::DiscountAllowed, $entry->discount_allowed,
            AccountCode::DiscountAllowed, $receivables);

        $add(TransactionType::BadDebt, $entry->bad_debt,
            AccountCode::BadDebts, $receivables);

        // --- Companies -----------------------------------------------------
        $add(
            TransactionType::CompanyPayment, $entry->company_payment_cash,
            $payables, AccountCode::Cash,
            $entry->company_note ? 'Paid — '.$entry->company_note : 'Company payment',
        );

        $add(TransactionType::DiscountReceived, $entry->discount_received,
            $payables, AccountCode::DiscountReceived);

        // --- Cash movements -------------------------------------------------
        /*
         * Expenses, head by head where the day names them. There is no credit
         * half to split as purchases and sales have — an expense is money out —
         * so each line is one posting against its own ledger.
         */
        foreach ($this->expenseAllocations($entry, $expenses) as $allocation) {
            $add(
                TransactionType::Expense, $allocation['amount'],
                $allocation['account'], AccountCode::Cash,
                'Expense — '.$allocation['label'],
            );
        }

        // Owners take cash from the till. Without a first-class action for it,
        // the cash balance never reconciles and somebody invents a fake expense.
        $add(TransactionType::OwnerDrawing, $entry->owner_drawing,
            AccountCode::OwnerDrawings, AccountCode::Cash);

        $add(TransactionType::OwnerCapital, $entry->owner_capital,
            AccountCode::Cash, AccountCode::OwnerCapital);

        return $specs;
    }

    /**
     * Splits the day's buying into what each company is owed.
     *
     * Falls back to a single unattributed allocation when the day was entered
     * as plain totals, so a quick day stays quick.
     *
     * @return array<int, array{label: string, account: AccountCode|string, covered: Money, pending: Money, excess: Money}>
     */
    private function purchaseAllocations(DailyEntry $entry, AccountCode|string $payables): array
    {
        $lines = $entry->purchaseLines()->with('company.account')->get();

        if ($lines->isEmpty()) {
            return [$this->split(
                $entry->company_note ?: 'companies',
                $payables,
                $entry->purchaseNet(),
                $entry->purchase_paid,
            )];
        }

        $discounts = $this->allocate(
            $entry->purchase_discount,
            $lines->map(fn (PurchaseLine $l) => $l->amount)->all()
        );

        return $lines->values()->map(fn (PurchaseLine $line, int $index) => $this->split(
            $line->label(),
            $line->company?->account?->code ?? $payables,
            $line->amount->minus($discounts[$index]),
            $line->paid,
        ))->all();
    }

    /**
     * Splits the day's selling into what each pharmacy owes.
     *
     * Falls back to a single unattributed allocation when the day was entered
     * as plain totals, so a quick day stays quick.
     *
     * @return array<int, array{label: string, account: AccountCode|string, covered: Money, pending: Money, excess: Money}>
     */
    private function saleAllocations(DailyEntry $entry, AccountCode|string $receivables): array
    {
        $lines = $entry->saleLines()->with('pharmacy.account')->get();

        if ($lines->isEmpty()) {
            return [$this->split(
                'market',
                $receivables,
                $entry->totalSales(),
                $entry->sale_cash,
            )];
        }

        return $lines->map(fn (SaleLine $line) => $this->split(
            $line->label(),
            $line->pharmacy?->account?->code ?? $receivables,
            $line->amount,
            $line->received,
        ))->all();
    }

    /**
     * Splits the day's spending across the heads it was filed under.
     *
     * Falls back to a single unattributed amount when the day was entered as a
     * plain total, so a quick day stays quick.
     *
     * @return array<int, array{label: string, account: AccountCode|string, amount: Money}>
     */
    private function expenseAllocations(DailyEntry $entry, AccountCode|string $expenses): array
    {
        $lines = $entry->expenseLines()->with('category.account')->get();

        if ($lines->isEmpty()) {
            return [[
                'label' => 'operating expenses',
                'account' => $expenses,
                'amount' => $entry->expenses_cash,
            ]];
        }

        return $lines->map(fn (ExpenseLine $line) => [
            'label' => $line->label(),
            'account' => $line->category?->account?->code ?? $expenses,
            'amount' => $line->amount,
        ])->all();
    }

    /**
     * Divides what was handed over between the goods and any earlier debt.
     *
     * @return array{label: string, account: AccountCode|string, covered: Money, pending: Money, excess: Money}
     */
    private function split(string $label, AccountCode|string $account, Money $net, Money $paid): array
    {
        $covered = $paid->greaterThan($net) ? $net : $paid;

        // A credit note bigger than the bill would make the cost negative;
        // clamp so the goods never add less than nothing to stock.
        if ($covered->isNegative()) {
            $covered = Money::zero();
        }

        return [
            'label' => $label,
            'account' => $account,
            'covered' => $covered,
            'pending' => $net->minus($covered)->isNegative() ? Money::zero() : $net->minus($covered),
            'excess' => $paid->minus($covered)->isNegative() ? Money::zero() : $paid->minus($covered),
        ];
    }

    /**
     * Spreads an amount across weights in proportion, exactly.
     *
     * The remainder goes to the largest weight rather than being dropped, so
     * the parts always add back to the whole — a rounding crumb left behind
     * here would unbalance a transaction.
     *
     * @param  array<int, Money>  $weights
     * @return array<int, Money>
     */
    private function allocate(Money $amount, array $weights): array
    {
        $total = Money::sum($weights);

        if ($amount->isZero() || $total->isZero()) {
            return array_fill(0, max(count($weights), 1), Money::zero());
        }

        $shares = [];
        $running = Money::zero();

        foreach ($weights as $weight) {
            $share = Money::of(
                bcdiv(bcmul($amount->toDecimal(), $weight->toDecimal(), 6), $total->toDecimal(), 2)
            );
            $shares[] = $share;
            $running = $running->plus($share);
        }

        $remainder = $amount->minus($running);

        if (! $remainder->isZero()) {
            $largest = 0;

            foreach ($weights as $i => $weight) {
                if ($weight->greaterThan($weights[$largest])) {
                    $largest = $i;
                }
            }

            $shares[$largest] = $shares[$largest]->plus($remainder);
        }

        return $shares;
    }
}
