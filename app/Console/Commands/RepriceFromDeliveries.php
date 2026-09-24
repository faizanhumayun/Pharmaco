<?php

namespace App\Console\Commands;

use App\Domain\Products\Actions\RecordProductPrice;
use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Brings the catalogue into line with deliveries already recorded.
 *
 * Receiving now takes the invoiced rate as what the goods cost, and says so in
 * the product's price history. Deliveries recorded before that left the
 * catalogue on whatever the last price list said, so this walks them in the
 * order they arrived — newest wins, because the newest bill is the current
 * price — and records the move against the delivery that made it.
 */
class RepriceFromDeliveries extends Command
{
    protected $signature = 'products:reprice-from-deliveries {business? : slug, or every business}
                            {--dry-run : list what would change and stop}';

    protected $description = 'Update catalogue rates from deliveries already received';

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
            $moved = 0;

            $orders = Order::forBusiness($business)
                ->where('status', OrderStatus::Received)
                ->with('receiptLines.product')
                ->orderBy('received_at')
                ->orderBy('id')
                ->get();

            foreach ($orders as $order) {
                foreach ($order->receiptLines as $line) {
                    $product = $line->product;

                    if ($product === null || $line->rate === null || ! $line->rate->isPositive()) {
                        continue;
                    }

                    if ($product->purchase_rate !== null && $product->purchase_rate->equals($line->rate)) {
                        continue;
                    }

                    $this->line(sprintf(
                        '  %-34s %8s → %-8s  (%s)',
                        mb_substr($product->brand_name, 0, 34),
                        $product->purchase_rate?->format() ?? '—',
                        $line->rate->format(),
                        $order->reference,
                    ));

                    $moved++;

                    if ($this->option('dry-run')) {
                        continue;
                    }

                    $product->forceFill([
                        'purchase_rate' => $line->rate->toDecimal(),
                        'priced_on' => ($order->received_at ?? $order->business_date)->toDateString(),
                    ])->save();

                    $prices->handle(
                        $product->fresh(),
                        $by,
                        date: $order->received_at ?? $order->business_date,
                        source: $order,
                    );
                }
            }

            $this->info("{$business->name}: {$moved} ".($this->option('dry-run') ? 'would move' : 'repriced'));
        }

        return self::SUCCESS;
    }
}
