<?php

namespace App\Console\Commands;

use App\Domain\Products\Actions\RecordProductPrice;
use App\Models\Business;
use App\Models\CompanyProduct;
use App\Models\User;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Multiplies every product's MRP by a factor.
 *
 * For trying things out on real-looking data, where a catalogue imported with
 * MRP equal to the trade price leaves nothing to see. It touches the printed
 * pack price and nothing else — not what the goods cost, not what the till
 * charges where a trade price exists, and not a figure in the ledger.
 *
 * Every change is written to the product's price history like any other, so
 * the previous figure is on the record and running it with the inverse factor
 * puts it back.
 */
class ScaleProductMrp extends Command
{
    protected $signature = 'products:scale-mrp {business : slug} {factor : e.g. 3 or 0.3333333333}
                            {--dry-run : show what would change and stop}';

    protected $description = 'Multiply every product MRP for a business by a factor';

    public function handle(RecordProductPrice $prices): int
    {
        $business = Business::where('slug', $this->argument('business'))->first();

        if ($business === null) {
            $this->error('No business with that slug.');

            return self::FAILURE;
        }

        $factor = (string) $this->argument('factor');

        if (! is_numeric($factor) || (float) $factor <= 0) {
            $this->error('The factor has to be a positive number.');

            return self::FAILURE;
        }

        $by = User::where('is_platform_admin', true)->orderBy('id')->first();
        $changed = 0;
        $tillMoved = 0;

        foreach (CompanyProduct::forBusiness($business)->get() as $product) {
            if ($product->mrp === null || ! $product->mrp->isPositive()) {
                continue;
            }

            $was = $product->mrp;
            $now = $was->times($factor);

            if ($was->equals($now)) {
                continue;
            }

            // A product with no trade price sells at its MRP, so this moves
            // what the counter charges as well. Counted, because that is the
            // part someone would not expect.
            if ($product->trade_price === null || ! $product->trade_price->isPositive()) {
                $tillMoved++;
            }

            if ($this->option('dry-run')) {
                $changed++;

                continue;
            }

            $product->forceFill([
                'mrp' => $now->toDecimal(),
                'priced_on' => $business->today()->toDateString(),
            ])->save();

            $prices->handle($product->fresh(), $by);
            $changed++;
        }

        $verb = $this->option('dry-run') ? 'would change' : 'changed';

        $this->info("{$business->name}: {$verb} {$changed} MRPs by ×{$factor}.");
        $this->line("  {$tillMoved} of them have no trade price, so the till price moves too.");

        return self::SUCCESS;
    }
}
