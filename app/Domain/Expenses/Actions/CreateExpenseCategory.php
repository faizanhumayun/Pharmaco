<?php

namespace App\Domain\Expenses\Actions;

use App\Domain\Ledger\ChartOfAccounts;
use App\Enums\AccountCode;
use App\Models\Account;
use App\Models\Business;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Adds an expense head and its own sub-ledger.
 *
 * The third of the same shape, after CreateCompany and CreatePharmacy. One
 * difference matters: categories are seeded with the business and start out
 * pointing at the control account itself, so the moment a sub-ledger opens and
 * closes 6000 to direct postings, every existing category has to be moved onto
 * a child of its own — otherwise the next Freight expense posts to a closed
 * account and the poster refuses it.
 */
class CreateExpenseCategory
{
    public function __construct(private readonly ChartOfAccounts $chart) {}

    /** @param array<string, mixed> $data */
    public function handle(Business $business, array $data, User $by): ExpenseCategory
    {
        return DB::transaction(function () use ($business, $data, $by) {
            // Moves any aggregate balance to Unallocated and closes the control
            // account to direct postings. Idempotent after the first category.
            $this->chart->openSubLedger($business, AccountCode::OperatingExpenses, $by);

            $parent = $this->parent($business);

            // account_id is not nullable, so the category is born pointing at
            // the parent and settled onto its own child a line later — inside
            // the same transaction, so nothing ever sees the halfway state.
            $category = ExpenseCategory::create([
                'business_id' => $business->id,
                'name' => $data['name'],
                'account_id' => $parent->id,
                'is_active' => true,
                'sort_order' => (int) ExpenseCategory::forBusiness($business)->max('sort_order') + 10,
            ]);

            $this->settleAccounts($business);

            return $category->fresh('account');
        });
    }

    /**
     * Gives every category of this business its own ledger under 6000.
     *
     * Idempotent, and safe to call whenever a category might still be pointing
     * at the parent — which is the state every business is seeded in.
     */
    public function settleAccounts(Business $business): void
    {
        $parent = $this->parent($business);

        $categories = ExpenseCategory::forBusiness($business)
            ->with('account')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        foreach ($categories as $category) {
            if ($category->account?->parent_id === $parent->id) {
                continue;
            }

            // -000 is Unallocated, so counting the existing children numbers
            // the next one from 001 upwards.
            $suffix = str_pad(
                (string) Account::query()->forBusiness($business)->where('parent_id', $parent->id)->count(),
                3,
                '0',
                STR_PAD_LEFT,
            );

            $account = $this->chart->addSubAccount(
                $business,
                AccountCode::OperatingExpenses,
                $suffix,
                $category->name,
                $category,
            );

            $category->forceFill(['account_id' => $account->id])->save();
        }
    }

    private function parent(Business $business): Account
    {
        return Account::query()->forBusiness($business)->code(AccountCode::OperatingExpenses)->firstOrFail();
    }
}
