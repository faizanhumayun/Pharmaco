<?php

namespace App\Domain\Orders\Actions;

use App\Domain\Stock\Actions\RecordStockMovements;
use App\Enums\OrderStatus;
use App\Models\CompanyProduct;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records what actually arrived against an order.
 *
 * The order is not touched beyond being marked received — it has to keep saying
 * what was asked for, because the difference between the two documents is the
 * thing worth having. A line received in full, short, over, or not at all is
 * recorded the same way; the shortfalls are found by comparing, not by being
 * flagged here.
 *
 * Nothing posts. The delivery is a fact about goods, not about money: the
 * invoice is entered in the daily entry as it always was.
 */
class ReceiveOrder
{
    public function __construct(
        private readonly RecordDeliveryPurchase $purchase,
        private readonly RecordStockMovements $movements,
        private readonly \App\Domain\Products\Actions\RecordProductPrice $prices,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Order $order, array $data, User $by): Order
    {
        // Sent means recording it for the first time; received means correcting
        // what was recorded. A draft has not gone anywhere yet.
        if ($order->status === OrderStatus::Draft) {
            throw new RuntimeException('This order has not been sent yet.');
        }

        // Remembered before the status moves, so the trail can tell recording
        // a delivery apart from correcting one.
        $wasReceived = $order->status === OrderStatus::Received;

        return DB::transaction(function () use ($order, $data, $by, $wasReceived) {
            $order->loadLines();

            // Rewriting the receipt is allowed right up until it is saved, and
            // saving it is what closes the order — so the table is cleared and
            // rebuilt rather than merged into.
            $order->receiptLines()->delete();

            $position = 0;

            foreach ($order->lines as $line) {
                $row = $data['lines'][$line->id] ?? [];
                $packs = $this->packsFor($row, $line->case_size);

                // No figure at all means the line was never checked off, and
                // no receipt line is written — its absence is what that means.
                // A recorded zero is different: someone looked, none had come,
                // and they may have said why. That gets a line.
                if ($packs === null) {
                    continue;
                }

                $order->receiptLines()->create([
                    'order_line_id' => $line->id,
                    'company_product_id' => $line->company_product_id,
                    'position' => $position++,
                    'code' => $line->code,
                    'brand_name' => $line->brand_name,
                    'generic_name' => $line->generic_name,
                    'strength' => $line->strength,
                    'dosage_form' => $line->dosage_form,
                    'pack_size' => $line->pack_size,
                    'case_size' => $line->case_size,
                    'cartons' => $this->cartons($packs, $line->case_size),
                    'packs' => $packs,
                    'rate' => $line->rate,
                    'discount_percent' => $line->discount_percent,
                    'note' => $this->note($row['note'] ?? null),
                ]);
            }

            foreach ($this->extras($order, $data['extras'] ?? []) as $extra) {
                $order->receiptLines()->create($extra + ['position' => $position++]);
            }

            // Nothing was checked off at all. Closing the order on that would
            // say the delivery came and was empty, which is not what anyone
            // means by it — they have simply not ticked anything yet. A genuine
            // empty delivery is recorded by marking the lines "none arrived",
            // which leaves rows behind and satisfies this.
            if ($order->receiptLines()->count() === 0) {
                throw new RuntimeException(
                    'Nothing has been checked off, so there is no delivery to record. '
                    .'Tick what arrived, or use Record change to mark a line as none arrived.'
                );
            }

            // The invoice, if a payment or a number was entered. The bill is
            // not taken from the form: it is what the delivery comes to at the
            // order's own rates, worked out here from the lines just written.
            $this->purchase->handle($order, [
                ...$data,
                'bill_amount' => $order->load('receiptLines')->receivedTotal()->toDecimal(),
            ], $by);

            $order->forceFill([
                'status' => OrderStatus::Received,
                'received_at' => now(),
                'received_by' => $by->id,
            ])->save();

            // The packs themselves. Written after the status, so the movements
            // are dated by when the delivery was received rather than by when
            // the order was raised.
            $written = $this->movements->forDelivery($order->fresh(), $by);

            // And what the company actually charged for them.
            $this->repriceFrom($order->fresh()->load('receiptLines.product'), $by);

            /*
             * The delivery, in the trail.
             *
             * What was ordered against what came is the part worth being able
             * to answer later, so the exceptions are counted here rather than
             * left to be recomputed from two documents months afterwards.
             */
            $order = $order->fresh()->loadLines()->load('receiptLines', 'purchaseLine');
            $exceptions = $order->deliveryExceptions();

            activity()
                ->performedOn($order)
                ->causedBy($by)
                ->withProperties([
                    'reference' => $order->reference,
                    'company' => $order->company->name,
                    'ordered' => $order->total()->toDecimal(),
                    'received' => $order->receivedTotal()->toDecimal(),
                    'lines_received' => $order->receiptLines->count(),
                    'differences' => $exceptions->count(),
                    'short' => $exceptions->filter(fn (array $r) => $r['difference'] < 0)->count(),
                    'over' => $exceptions->filter(fn (array $r) => $r['difference'] > 0)->count(),
                    'stock_movements' => $written,
                    'invoice_no' => $order->purchaseLine?->invoice_no,
                    'paid' => $order->purchaseLine?->paid->toDecimal(),
                ])
                ->event($wasReceived ? 'delivery.corrected' : 'delivery.received')
                ->log($wasReceived ? 'Delivery corrected' : 'Delivery recorded');

            return $order->fresh()->loadLines()->load('receiptLines');
        });
    }

    /**
     * Extra products the company sent that were never ordered.
     *
     * Checked against the company's own catalogue, so an unordered item is
     * still a real product of theirs rather than whatever the form said.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    /**
     * Brings the catalogue into line with what was invoiced.
     *
     * The rate on a delivery is what the goods cost — that is not an opinion,
     * it is the bill. Leaving the catalogue on last month's figure meant every
     * margin, every stock valuation and the counter's own price floor went on
     * using a number the company had stopped charging, and the product's price
     * history showed nothing had happened.
     *
     * The move is recorded against this delivery, so the history says which
     * one moved it and a wrong rate can be traced and corrected rather than
     * silently becoming the truth.
     */
    private function repriceFrom(Order $order, User $by): void
    {
        foreach ($order->receiptLines as $line) {
            $product = $line->product;

            if ($product === null || $line->rate === null || ! $line->rate->isPositive()) {
                continue;
            }

            if ($product->purchase_rate === null || ! $product->purchase_rate->equals($line->rate)) {
                $product->forceFill([
                    'purchase_rate' => $line->rate->toDecimal(),
                    'priced_on' => ($order->received_at ?? $order->business_date)->toDateString(),
                ])->save();
            }

            /*
             * Recorded for every delivered product, not only the ones whose
             * cost moved: the selling prices entered with the delivery are
             * already on the product by now, and this is the one row that
             * carries all three. Identical figures record nothing.
             */
            $this->prices->handle(
                $product->fresh(),
                $by,
                date: $order->received_at ?? $order->business_date,
                source: $order,
            );
        }
    }

    private function extras(Order $order, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $catalogue = CompanyProduct::forBusiness($order->business_id)
            ->where('company_id', $order->company_id)
            ->get()
            ->keyBy('id');

        $extras = [];

        foreach ($rows as $row) {
            $product = isset($row['company_product_id']) && $row['company_product_id'] !== ''
                ? $catalogue->get((int) $row['company_product_id'])
                : null;

            $brand = $product?->brand_name ?? trim((string) ($row['brand_name'] ?? ''));

            if ($brand === '') {
                continue;
            }

            $caseSize = $product?->case_size;
            $packs = $this->packsFor($row, $caseSize);

            // An extra with nothing in it is not an extra.
            if ($packs === null || $packs < 1) {
                continue;
            }

            $rate = $row['rate'] ?? null;
            $rate = $rate === null || $rate === ''
                ? ($product?->purchase_rate ?? $product?->trade_price ?? Money::zero())
                : Money::of((string) $rate);

            $extras[] = [
                'order_line_id' => null,
                'company_product_id' => $product?->id,
                'code' => $product?->code,
                'brand_name' => mb_substr($brand, 0, 200),
                'generic_name' => $product?->generic_name,
                'strength' => $product?->strength,
                'dosage_form' => $product?->dosage_form,
                'pack_size' => $product?->pack_size,
                'case_size' => $caseSize,
                'cartons' => $this->cartons($packs, $caseSize),
                'packs' => $packs,
                'rate' => $rate,
                'discount_percent' => null,
                'note' => $this->note($row['note'] ?? null),
            ];
        }

        return $extras;
    }

    /**
     * Packs from whichever of the two boxes was filled in.
     *
     * Null means nothing arrived, which is different from zero being typed —
     * both end up as no receipt line, and both read as a full shortfall.
     *
     * @param  array<string, mixed>  $row
     */
    private function packsFor(array $row, ?int $caseSize): ?int
    {
        $cartons = $this->integer($row['cartons'] ?? null);

        if ($cartons !== null && $cartons > 0 && $caseSize !== null && $caseSize > 0) {
            return $cartons * $caseSize;
        }

        return $this->integer($row['packs'] ?? null);
    }

    private function cartons(int $packs, ?int $caseSize): ?int
    {
        return $packs > 0 && $caseSize !== null && $caseSize > 0 && $packs % $caseSize === 0
            ? intdiv($packs, $caseSize)
            : null;
    }

    private function note(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /** Null for blank or nonsense; zero is a legitimate answer. */
    private function integer(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' || ! ctype_digit($value) ? null : (int) $value;
    }
}
