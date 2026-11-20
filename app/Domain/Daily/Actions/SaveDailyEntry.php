<?php

namespace App\Domain\Daily\Actions;

use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Expenses\Actions\CreateExpenseCategory;
use App\Domain\Pharmacies\Actions\CreatePharmacy;
use App\Enums\DocumentStatus;
use App\Exceptions\ImmutableRecordException;
use App\Models\Business;
use App\Models\Company;
use App\Models\DailyEntry;
use App\Models\ExpenseCategory;
use App\Models\Pharmacy;
use App\Models\SaleLine;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class SaveDailyEntry
{
    public function __construct(
        private readonly CreateCompany $createCompany,
        private readonly CreatePharmacy $createPharmacy,
        private readonly CreateExpenseCategory $createExpenseCategory,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Business $business, array $data, User $by): DailyEntry
    {
        return DB::transaction(function () use ($business, $data, $by) {
            $entry = DailyEntry::forBusiness($business)
                ->where('business_date', $data['business_date'])
                ->first();

            if ($entry && ! $entry->isEditable()) {
                throw ImmutableRecordException::forDocument('daily entry');
            }

            $entry ??= new DailyEntry([
                'business_id' => $business->id,
                'business_date' => $data['business_date'],
                'created_by' => $by->id,
            ]);

            $amounts = [];

            foreach (DailyEntry::MONEY_FIELDS as $field) {
                $amounts[$field] = Money::of($data[$field] ?? null)->toDecimal();
            }

            /*
             * Credit sales are what the market did not pay for today. Derived
             * here rather than in the form request so every caller gets it —
             * the two figures can never contradict each other.
             */
            if (array_key_exists('sale_total', $data)) {
                $credit = Money::of($data['sale_total'])->minus($amounts['sale_cash']);
                $amounts['sale_credit'] = ($credit->isNegative() ? Money::zero() : $credit)->toDecimal();
            }

            $entry->fill([
                'business_id' => $business->id,
                'business_date' => $data['business_date'],
                'status' => DocumentStatus::Draft,
                'company_note' => $data['company_note'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $entry->created_by ?? $by->id,
                ...$amounts,
            ])->save();

            $lines = $this->savePurchaseLines($entry, $business, $data['purchases'] ?? [], $by);

            // Where invoices were listed, they are the record — the header
            // totals follow from them rather than being typed twice.
            if ($lines->isNotEmpty()) {
                $entry->forceFill([
                    'purchase_total' => Money::sum($lines->pluck('amount'))->toDecimal(),
                    'purchase_paid' => Money::sum($lines->pluck('paid'))->toDecimal(),
                ])->save();
            }

            /*
             * Only touched when the caller says something about collections.
             * The daily form knows nothing about them, and a form that stays
             * silent must not wipe what the collections screen recorded.
             */
            if (array_key_exists('collections', $data)) {
                $collectionLines = $this->saveCollectionLines($entry, $business, $data['collections'], $by);

                // Named recovery is the record; the day's figure follows it,
                // exactly as invoices govern the purchase totals above.
                if ($collectionLines->isNotEmpty()) {
                    $entry->forceFill([
                        'collection_cash' => Money::sum($collectionLines->pluck('amount'))->toDecimal(),
                    ])->save();
                }
            }

            $saleLines = $this->saveSaleLines($entry, $business, $data['sales'] ?? [], $by);

            // The same rule on the selling side. Taking more than the invoice
            // is a recovery against earlier credit, not a bigger sale, so it is
            // held back out of both figures — saleExcess() reports it.
            if ($saleLines->isNotEmpty()) {
                $covered = Money::sum($saleLines->map(
                    fn (SaleLine $line) => $line->received->greaterThan($line->amount)
                        ? $line->amount
                        : $line->received
                ));

                $entry->forceFill([
                    'sale_cash' => $covered->toDecimal(),
                    'sale_credit' => Money::sum($saleLines->pluck('amount'))->minus($covered)->toDecimal(),
                ])->save();
            }

            /*
             * Returns follow the same rule as collections: the counter records
             * them, the daily form knows nothing of them, and a form that stays
             * silent must not wipe them. Where they exist, they are the figure.
             */
            if (array_key_exists('returns', $data)) {
                $this->saveReturnLines($entry, $business, $data['returns']);
            }

            $returnLines = $entry->returnLines()->get();

            if ($returnLines->isNotEmpty()) {
                $entry->forceFill([
                    'sales_return' => Money::sum($returnLines->pluck('amount'))->toDecimal(),
                ])->save();
            }

            $expenseLines = $this->saveExpenseLines($entry, $business, $data['expenses'] ?? [], $by);

            // And on the spending side. An expense has no credit half to split:
            // it is money out, so the day's total is simply the lines added up.
            if ($expenseLines->isNotEmpty()) {
                $entry->forceFill([
                    'expenses_cash' => Money::sum($expenseLines->pluck('amount'))->toDecimal(),
                ])->save();
            }

            // Stored so the day can be audited against what was computed at the
            // time, even if the derivation changes later.
            $entry->forceFill(['derived_cogs' => $entry->costOfGoodsSold()->toDecimal()])->save();

            return $entry->fresh();
        });
    }

    /**
     * Replaces the day's invoice detail.
     *
     * A company name that does not exist yet is created along with its ledger,
     * so attributing a purchase never means leaving the form first.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function savePurchaseLines(DailyEntry $entry, Business $business, array $rows, User $by)
    {
        $entry->purchaseLines()->delete();

        foreach ($rows as $row) {
            $amount = Money::of($row['amount'] ?? null);
            $paid = Money::of($row['paid'] ?? null);

            // A row with no goods but a payment is how an earlier bill is settled.
            if ($amount->isZero() && $paid->isZero()) {
                continue;
            }

            $name = trim((string) ($row['company'] ?? ''));
            $company = null;

            if ($name !== '') {
                $company = Company::forBusiness($business)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first()
                    ?? $this->createCompany->handle($business, ['name' => $name], $by);
            }

            $entry->purchaseLines()->create([
                'company_id' => $company?->id,
                'company_name' => $company?->name ?? ($name ?: null),
                // Carried through because this method rewrites the day's lines
                // from the form; without it, editing the day would quietly cut
                // an invoice loose from the order it came in on.
                'order_id' => $row['order_id'] ?? null,
                'invoice_no' => $row['invoice_no'] ?? null,
                'amount' => $amount->toDecimal(),
                'paid' => $paid->toDecimal(),
            ]);
        }

        return $entry->purchaseLines()->get();
    }

    /**
     * Replaces the day's customer-level recovery detail.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function saveCollectionLines(DailyEntry $entry, Business $business, array $rows, User $by)
    {
        $entry->collectionLines()->delete();

        foreach ($rows as $row) {
            $amount = Money::of($row['amount'] ?? null);

            if ($amount->isZero()) {
                continue;
            }

            $name = trim((string) ($row['pharmacy'] ?? ''));
            $pharmacy = null;

            if ($name !== '') {
                $pharmacy = Pharmacy::forBusiness($business)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first()
                    ?? $this->createPharmacy->handle($business, ['name' => $name], $by);
            }

            $line = $entry->collectionLines()->create([
                'pharmacy_id' => $pharmacy?->id,
                'pharmacy_name' => $pharmacy?->name ?? ($name ?: null),
                'amount' => $amount->toDecimal(),
                'note' => $row['note'] ?? null,
                'business_payment_method_id' => $this->method($business, $row['via'] ?? null),
            ]);

            // Which bills it paid, put back as they were.
            foreach ($row['allocations'] ?? [] as $allocation) {
                $against = Money::of($allocation['amount'] ?? null);

                if ($against->isZero() || empty($allocation['pos_bill_id'])) {
                    continue;
                }

                $line->allocations()->create([
                    'pos_bill_id' => $allocation['pos_bill_id'],
                    'amount' => $against->toDecimal(),
                ]);
            }
        }

        return $entry->collectionLines()->get();
    }

    /**
     * Replaces the day's pharmacy-level sales detail.
     *
     * A pharmacy name that does not exist yet is created along with its ledger,
     * so naming the buyer never means leaving the form first.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function saveSaleLines(DailyEntry $entry, Business $business, array $rows, User $by)
    {
        /*
         * The daily form lists invoices without saying how each was paid, so a
         * row that is silent about it keeps what its invoice already had. A
         * bill taken by JazzCash must not turn into cash because someone
         * re-saved the day.
         */
        $paidBy = $entry->saleLines()->whereNotNull('invoice_no')
            ->pluck('business_payment_method_id', 'invoice_no');

        $entry->saleLines()->delete();

        foreach ($rows as $row) {
            $amount = Money::of($row['amount'] ?? null);
            $received = Money::of($row['received'] ?? null);

            // A row with no goods but cash taken is how earlier credit is recovered.
            if ($amount->isZero() && $received->isZero()) {
                continue;
            }

            $name = trim((string) ($row['pharmacy'] ?? ''));
            $pharmacy = null;

            if ($name !== '') {
                $pharmacy = Pharmacy::forBusiness($business)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first()
                    ?? $this->createPharmacy->handle($business, ['name' => $name], $by);
            }

            $entry->saleLines()->create([
                'pharmacy_id' => $pharmacy?->id,
                'pharmacy_name' => $pharmacy?->name ?? ($name ?: null),
                'invoice_no' => $row['invoice_no'] ?? null,
                'amount' => $amount->toDecimal(),
                'received' => $received->toDecimal(),
                'business_payment_method_id' => $this->method(
                    $business,
                    array_key_exists('via', $row) ? $row['via'] : ($paidBy[$row['invoice_no'] ?? ''] ?? null),
                ),
            ]);
        }

        return $entry->saleLines()->get();
    }

    /**
     * Replaces the day's returns.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function saveReturnLines(DailyEntry $entry, Business $business, array $rows): void
    {
        $entry->returnLines()->delete();

        foreach ($rows as $row) {
            $amount = Money::of($row['amount'] ?? null);

            if (! $amount->isPositive()) {
                continue;
            }

            $refunded = Money::of($row['refunded'] ?? null);
            $name = trim((string) ($row['pharmacy'] ?? ''));

            // A return never invents a customer: it names one that a bill
            // already named, or nobody.
            $pharmacy = $name === '' ? null : Pharmacy::forBusiness($business)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            $entry->returnLines()->create([
                'pharmacy_id' => $pharmacy?->id,
                'pharmacy_name' => $pharmacy?->name ?? ($name ?: null),
                'reference' => $row['reference'] ?? null,
                'amount' => $amount->toDecimal(),
                'refunded' => ($refunded->greaterThan($amount) ? $amount : $refunded)->toDecimal(),
            ]);
        }
    }

    /**
     * The payment method a row names, if it is one of this business's own.
     * Anything else — nothing, or another tenant's id — is cash.
     */
    private function method(Business $business, mixed $id): ?int
    {
        if ($id === null || $id === '' || (int) $id === 0) {
            return null;
        }

        return \App\Models\BusinessPaymentMethod::forBusiness($business)->whereKey((int) $id)->value('id');
    }

    /**
     * Replaces the day's expense detail.
     *
     * A category name that does not exist yet is created along with its ledger,
     * so an unbudgeted-for expense never has to be filed under "Other".
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function saveExpenseLines(DailyEntry $entry, Business $business, array $rows, User $by)
    {
        $entry->expenseLines()->delete();

        foreach ($rows as $row) {
            $amount = Money::of($row['amount'] ?? null);
            $name = trim((string) ($row['category'] ?? ''));

            // Nothing spent, or nothing to file it under: not a line.
            if ($amount->isZero() || $name === '') {
                continue;
            }

            $category = ExpenseCategory::forBusiness($business)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first()
                ?? $this->createExpenseCategory->handle($business, ['name' => $name], $by);

            $entry->expenseLines()->create([
                'expense_category_id' => $category->id,
                'description' => $row['description'] ?? null,
                'amount' => $amount->toDecimal(),
            ]);
        }

        return $entry->expenseLines()->get();
    }
}
