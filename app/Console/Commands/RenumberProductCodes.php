<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\CompanyProduct;
use App\Support\CurrentBusiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives every product of a business the one kind of code: PRD- and its own
 * number, as new products get from the moment they are made.
 *
 * The code it had before is not lost where it is needed: a company's code is
 * in the match key that recognises the product on its next price list. One
 * entry goes in the activity log for the whole change, with a sample of what
 * each code was, rather than one entry per product.
 */
class RenumberProductCodes extends Command
{
    protected $signature = 'products:renumber-codes {business : the business slug} {--dry-run : say what would change, change nothing}';

    protected $description = 'Set every product code of a business to PRD- and the product\'s number';

    public function handle(CurrentBusiness $current): int
    {
        $business = Business::query()->where('slug', $this->argument('business'))->first();

        if ($business === null) {
            $this->error('No business with that slug.');

            return self::FAILURE;
        }

        if ($business->mirror_id !== null) {
            $this->error('That business is a read-only copy of a shop. Renumber it on the shop\'s own computer.');

            return self::FAILURE;
        }

        $current->set($business);

        $products = CompanyProduct::forBusiness($business)->get(['id', 'code']);
        $changing = $products->filter(fn (CompanyProduct $p) => $p->code !== CompanyProduct::codeFor($p->id));

        $this->info("{$business->name}: {$products->count()} products, {$changing->count()} to renumber.");

        if ($changing->isEmpty() || $this->option('dry-run')) {
            $changing->take(5)->each(fn ($p) => $this->line("  {$p->code} → ".CompanyProduct::codeFor($p->id)));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($business, $changing) {
            foreach ($changing->chunk(500) as $chunk) {
                foreach ($chunk as $product) {
                    $product->forceFill(['code' => CompanyProduct::codeFor($product->id)])->saveQuietly();
                }
            }

            activity()
                ->performedOn($business)
                ->withProperties([
                    'products' => $changing->count(),
                    'sample' => $changing->take(20)->mapWithKeys(fn ($p) => [CompanyProduct::codeFor($p->id) => $p->code])->all(),
                ])
                ->event('products.codes_renumbered')
                ->log('Product codes set to PRD- and the product\'s number');
        });

        $this->info("Done: {$changing->count()} codes changed.");

        return self::SUCCESS;
    }
}
