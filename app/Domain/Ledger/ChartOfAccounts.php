<?php

namespace App\Domain\Ledger;

use App\Enums\AccountCode;
use App\Models\Account;
use App\Domain\Ledger\PostingLine;
use App\Enums\TransactionType;
use App\Models\Business;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Seeds a business's chart of accounts.
 *
 * Runs when the business is created, so a business is never without the
 * accounts its ledger will post to. Idempotent, so a later phase can add a code
 * and backfill every existing business by running it again.
 */
class ChartOfAccounts
{
    /** @return Collection<string, Account> */
    public function seed(Business $business): Collection
    {
        $accounts = collect();
        $order = 0;

        foreach (AccountCode::cases() as $code) {
            $accounts[$code->value] = Account::updateOrCreate(
                ['business_id' => $business->id, 'code' => $code->value],
                [
                    'name' => $code->label(),
                    'type' => $code->type(),
                    'is_control' => $code->isControl(),
                    'is_postable' => $code->isPostable(),
                    'is_system' => true,
                    'sort_order' => $order += 10,
                    'description' => $code->description(),
                ]
            );
        }

        $this->seedExpenseCategories($business, $accounts[AccountCode::OperatingExpenses->value]);

        return $accounts;
    }

    /**
     * Categories are optional detail on top of the day's expense total, but
     * they cost nothing now and are painful to backfill later.
     */
    private function seedExpenseCategories(Business $business, $expenseAccount): void
    {
        $defaults = ['Freight', 'Fuel', 'Salaries', 'Rent', 'Utilities', 'Other'];

        foreach ($defaults as $index => $name) {
            ExpenseCategory::updateOrCreate(
                ['business_id' => $business->id, 'name' => $name],
                ['account_id' => $expenseAccount->id, 'is_active' => true, 'sort_order' => ($index + 1) * 10]
            );
        }
    }

    /**
     * Adds a sub-account under a control account and stops the parent being
     * posted to directly.
     *
     * Phase 9 uses this for per-company payables. The control balance then
     * equals the sum of its children by construction rather than by
     * maintenance — which is the whole reason the parent stops accepting
     * postings at the same moment.
     */
    public function addSubAccount(
        Business $business,
        AccountCode $parentCode,
        string $suffix,
        string $name,
        ?object $subject = null,
        bool $closeParent = true,
    ): Account {
        $parent = Account::query()->forBusiness($business)->code($parentCode)->firstOrFail();

        $child = Account::updateOrCreate(
            ['business_id' => $business->id, 'code' => "{$parentCode->value}-{$suffix}"],
            [
                'name' => $name,
                'type' => $parent->type,
                'parent_id' => $parent->id,
                'is_control' => false,
                'is_postable' => true,
                'is_system' => false,
                'sort_order' => $parent->sort_order + 1,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
            ]
        );

        if ($closeParent) {
            $parent->forceFill(['is_postable' => false])->save();
        }

        return $child;
    }

    /**
     * Prepares a control account for sub-ledgers.
     *
     * Ledger entries are append-only, so the balance already sitting on the
     * parent cannot be *moved* onto a child — it is transferred by a posting,
     * which leaves the history intact and the transfer visible. Everything the
     * summary-level daily entry posts afterwards lands in "Unallocated", because
     * a day's total genuinely does not know which company it belongs to.
     */
    public function openSubLedger(Business $business, AccountCode $parentCode, User $by): Account
    {
        $parent = Account::query()->forBusiness($business)->code($parentCode)->with('children')->firstOrFail();

        $existing = $parent->children->firstWhere('code', $parentCode->value . '-000');

        if ($existing !== null) {
            return $existing;
        }

        // Created without closing the parent yet: the transfer below still
        // needs to post to it.
        $unallocated = $this->addSubAccount(
            $business, $parentCode, '000', $parent->name . ' — Unallocated', null, closeParent: false
        );

        $balance = app(BalanceService::class)->directBalance($business, $parentCode);

        if (! $balance->isZero()) {
            $increasesOnDebit = $parentCode->type()->increasesOnDebit();

            $poster = app(LedgerPoster::class);

            // The first day still open — today, unless today has already been
            // closed, in which case the move could not be posted at all and
            // adding the first company (or expense head) failed outright.
            $poster->post(new PostingSpec(
                business: $business,
                date: $poster->firstOpenDay($business),
                type: TransactionType::Adjustment,
                lines: $balance->isPositive() === $increasesOnDebit
                    ? [
                        PostingLine::debit($unallocated->code, $balance->absolute()),
                        PostingLine::credit($parentCode, $balance->absolute()),
                    ]
                    : [
                        PostingLine::credit($unallocated->code, $balance->absolute()),
                        PostingLine::debit($parentCode, $balance->absolute()),
                    ],
                createdBy: $by,
                narration: 'Opened sub-ledger — balance moved to Unallocated',
                reason: 'Sub-accounts introduced; the control account stops taking direct postings.',
            ));
        }

        $parent->forceFill(['is_postable' => false])->save();

        return $unallocated;
    }

    /**
     * Where a summary posting for this control account should go.
     *
     * The parent while it has no children; "Unallocated" once it does.
     */
    public function postingCodeFor(Business $business, AccountCode $parentCode): string
    {
        $hasChildren = Account::query()
            ->forBusiness($business)
            ->whereHas('parent', fn ($q) => $q->where('code', $parentCode->value))
            ->exists();

        return $hasChildren ? $parentCode->value . '-000' : $parentCode->value;
    }
}
