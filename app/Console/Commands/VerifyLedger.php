<?php

namespace App\Console\Commands;

use App\Domain\Ledger\BalanceService;
use App\Enums\AccountCode;
use App\Models\Business;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Proves the ledger is intact, for every business.
 *
 * Meant to run nightly. If it fails, every figure the application reports is
 * suspect, and it is far better to learn that from a scheduled check than from
 * an owner who noticed their position looked wrong.
 */
class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify {--business= : Slug of a single business to check}';

    protected $description = 'Check that every business ledger balances and reconciles';

    public function handle(BalanceService $balances): int
    {
        $businesses = Business::query()
            ->when($this->option('business'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('name')
            ->get();

        if ($businesses->isEmpty()) {
            $this->warn('No businesses to check.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($businesses as $business) {
            $problems = $this->check($business, $balances);

            if ($problems === []) {
                $this->line("  <fg=green>✓</> {$business->name}");

                continue;
            }

            $failures++;
            $this->line("  <fg=red>✗</> {$business->name}");

            foreach ($problems as $problem) {
                $this->line("      <fg=red>{$problem}</>");
            }
        }

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} of {$businesses->count()} businesses failed integrity checks.");

            return self::FAILURE;
        }

        $this->info("All {$businesses->count()} businesses passed.");

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function check(Business $business, BalanceService $balances): array
    {
        $problems = [];

        // 1. The whole business balances.
        $trial = $balances->trialBalance($business);

        if (! $trial['balanced']) {
            $problems[] = sprintf(
                'Trial balance is out by %s (debits %s, credits %s).',
                $trial['difference']->format(),
                $trial['debits']->format(),
                $trial['credits']->format()
            );
        }

        // 2. Every individual transaction balances.
        $unbalanced = DB::table('ledger_entries')
            ->where('business_id', $business->id)
            ->groupBy('transaction_id')
            ->havingRaw('COALESCE(SUM(debit), 0) <> COALESCE(SUM(credit), 0)')
            ->pluck('transaction_id');

        if ($unbalanced->isNotEmpty()) {
            $problems[] = 'Unbalanced transactions: #' . $unbalanced->implode(', #') . '.';
        }

        // 3. Control accounts equal the sum of their children.
        // Operating Expenses is not a control account by declaration, but it
        // gains children the same way once expense heads get their own ledgers,
        // so it earns the same check.
        foreach ([AccountCode::MarketReceivables, AccountCode::CompanyPayables, AccountCode::OperatingExpenses] as $code) {
            if (! $balances->controlReconciles($business, $code)) {
                $problems[] = "Control account {$code->value} does not equal the sum of its sub-accounts.";
            }
        }

        // 4. Both derivations of net position agree.
        $position = $balances->position($business);

        if (! $position->isConsistent()) {
            $problems[] = sprintf(
                'Net position derivations disagree by %s (assets − liabilities %s, equity + profit %s).',
                $position->discrepancy()->format(),
                $position->netPosition()->format(),
                $position->equityDerivation()->format()
            );
        }

        // 5. No entry belongs to a different business than its transaction.
        $mismatched = DB::table('ledger_entries')
            ->join('transactions', 'transactions.id', '=', 'ledger_entries.transaction_id')
            ->where('ledger_entries.business_id', $business->id)
            ->whereColumn('transactions.business_id', '!=', 'ledger_entries.business_id')
            ->count();

        if ($mismatched > 0) {
            $problems[] = "{$mismatched} ledger entries belong to a different business than their transaction.";
        }

        // 6. No postable account has children.
        $badParents = DB::table('accounts as parent')
            ->join('accounts as child', 'child.parent_id', '=', 'parent.id')
            ->where('parent.business_id', $business->id)
            ->where('parent.is_postable', true)
            ->distinct()
            ->pluck('parent.code');

        if ($badParents->isNotEmpty()) {
            $problems[] = 'Accounts with sub-accounts are still postable: ' . $badParents->implode(', ') . '.';
        }

        return $problems;
    }
}
