<?php

namespace App\Domain\Stock\Audits\Actions;

use App\Domain\Stock\StockLedger;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyAudit;
use App\Models\CompanyAuditItem;
use App\Models\CompanyProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opens a company's rack for counting.
 *
 * One audit at a time per company: starting again while one is still being
 * counted or waits for a decision returns that one, so two people cannot
 * count the same rack into two reports. The sheet is fixed here — every
 * product of the company the records hold stock of, or the whole catalogue
 * when asked — so a product sold out halfway does not drop off it.
 */
class StartCompanyAudit
{
    public function __construct(private readonly StockLedger $stock) {}

    public function handle(Business $business, Company $company, User $by, bool $byBatch = true, bool $includeEmpty = false): CompanyAudit
    {
        if ($company->business_id !== $business->id) {
            throw new RuntimeException('That company belongs to another business.');
        }

        $open = CompanyAudit::forBusiness($business)
            ->where('company_id', $company->id)
            ->whereIn('status', [CompanyAudit::COUNTING, CompanyAudit::SUBMITTED])
            ->first();

        if ($open) {
            return $open;
        }

        $products = CompanyProduct::forBusiness($business)->where('company_id', $company->id)->get();
        $pieces = $this->stock->piecesByProduct($business, null, $products->pluck('id')->all());

        $sheet = $products->filter(fn (CompanyProduct $p) => (int) ($pieces[$p->id] ?? 0) !== 0
            || ($includeEmpty && $p->is_active));

        if ($sheet->isEmpty()) {
            throw new RuntimeException("The records hold no stock of {$company->name}. Tick \"Also list products with no stock\" to count its rack anyway.");
        }

        return DB::transaction(function () use ($business, $company, $by, $byBatch, $includeEmpty, $sheet) {
            // The till is read from the last audit of this company, so each
            // report covers the month since the one before.
            $last = CompanyAudit::forBusiness($business)
                ->where('company_id', $company->id)
                ->done()
                ->max('business_date');

            $audit = CompanyAudit::create([
                'business_id' => $business->id,
                'company_id' => $company->id,
                'business_date' => $business->today()->toDateString(),
                'status' => CompanyAudit::COUNTING,
                'by_batch' => $byBatch,
                'include_empty' => $includeEmpty,
                'period_from' => $last ?? $business->opening_date?->toDateString(),
                'started_by' => $by->id,
            ]);

            foreach ($sheet as $product) {
                CompanyAuditItem::create([
                    'business_id' => $business->id,
                    'company_audit_id' => $audit->id,
                    'company_product_id' => $product->id,
                ]);
            }

            return $audit;
        });
    }
}
