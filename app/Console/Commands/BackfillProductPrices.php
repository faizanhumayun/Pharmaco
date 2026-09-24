<?php

namespace App\Console\Commands;

use App\Domain\Products\Actions\RecordProductPrice;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Gives priced products that have no history their opening row.
 *
 * Price history used to be written only by the price-list import, so a
 * catalogue carried over from another system arrived fully priced and entirely
 * without a record of it — the product page said "No price history" about a
 * price printed directly above. This writes that first row, dated when the
 * product was priced, so there is something for later changes to be a change
 * from.
 *
 * Safe to run more than once: a product that already has history is left alone.
 */
class BackfillProductPrices extends Command
{
    protected $signature = 'products:backfill-prices {business? : slug, or every business}
                            {--dry-run : count what would be written and stop}';

    protected $description = 'Record an opening price for products that have prices but no history';

    public function handle(RecordProductPrice $prices): int
    {
        $by = User::where('is_platform_admin', true)->orderBy('id')->first();

        if ($by === null) {
            $this->error('No platform admin to record these against.');

            return self::FAILURE;
        }

        $businesses = $this->argument('business')
            ? Business::where('slug', $this->argument('business'))->get()
            : Business::all();

        foreach ($businesses as $business) {
            $missing = CompanyProduct::forBusiness($business)
                ->whereDoesntHave('prices')
                ->where(fn ($q) => $q->whereNotNull('mrp')->orWhereNotNull('trade_price')->orWhereNotNull('purchase_rate'))
                ->get();

            $this->line("{$business->name}: {$missing->count()} priced products with no history");

            if ($this->option('dry-run') || $missing->isEmpty()) {
                continue;
            }

            $written = 0;

            foreach ($missing as $product) {
                if ($prices->handle($product, $by) !== null) {
                    $written++;
                }
            }

            $this->info("  wrote {$written} opening prices");
        }

        return self::SUCCESS;
    }
}
