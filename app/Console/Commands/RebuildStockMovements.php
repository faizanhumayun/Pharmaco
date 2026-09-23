<?php

namespace App\Console\Commands;

use App\Domain\Stock\Actions\RecordStockMovements;
use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Rewrites every delivery's stock movements from the deliveries themselves.
 *
 * Movements are derived from receipt lines, so they can always be rebuilt from
 * them. Used to bring deliveries recorded before this existed into the count,
 * and as the answer to "are the quantities right?" — run it, and if anything
 * changes, the movements had drifted from the deliveries.
 *
 * Adjustments made by hand are left alone: nothing else records them, so there
 * is nothing to rebuild them from.
 */
class RebuildStockMovements extends Command
{
    protected $signature = 'stock:rebuild {--business= : Slug of one business, otherwise all}';

    protected $description = 'Rebuild stock movements from every recorded delivery';

    public function handle(RecordStockMovements $writer): int
    {
        $businesses = Business::query()
            ->when($this->option('business'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($businesses->isEmpty()) {
            $this->error('No business matched.');

            return self::FAILURE;
        }

        foreach ($businesses as $business) {
            $orders = Order::forBusiness($business)
                ->where('status', OrderStatus::Received)
                ->with('receiptLines')
                ->get();

            $movements = 0;

            foreach ($orders as $order) {
                // Attributed to whoever recorded the delivery, not to whoever
                // happens to be running the command.
                $by = $order->received_by
                    ? User::find($order->received_by)
                    : User::find($order->created_by);

                $movements += $writer->forDelivery($order, $by);
            }

            $this->line(sprintf(
                '%-28s %d deliveries → %d movements',
                $business->name,
                $orders->count(),
                $movements,
            ));
        }

        return self::SUCCESS;
    }
}
