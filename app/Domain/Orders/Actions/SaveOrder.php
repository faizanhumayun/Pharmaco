<?php

namespace App\Domain\Orders\Actions;

use App\Enums\OrderStatus;
use App\Models\Business;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes an order form — new or being amended while it is still a draft.
 *
 * The descriptive half of every line is taken from the catalogue rather than
 * from the form, so what the order says a product is cannot be edited in the
 * browser into something the company never sold. The quantity and the rate do
 * come from the form, because those are exactly what is being negotiated.
 */
class SaveOrder
{
    /** @param array<string, mixed> $data */
    public function handle(Business $business, Company $company, array $data, User $by, ?Order $order = null): Order
    {
        if ($order !== null && ! $order->isEditable()) {
            throw new RuntimeException('This order has been sent and cannot be changed.');
        }

        return DB::transaction(function () use ($business, $company, $data, $by, $order) {
            // Everything this company sells, to check each line against.
            $catalogue = CompanyProduct::forBusiness($business)
                ->where('company_id', $company->id)
                ->get()
                ->keyBy('id');

            $lines = $this->buildLines($data['lines'] ?? [], $catalogue);

            if ($lines === []) {
                throw new RuntimeException('An order form needs at least one product on it.');
            }

            $attributes = [
                'business_id' => $business->id,
                'company_id' => $company->id,
                'business_date' => $data['business_date'] ?? $business->today()->toDateString(),
                'discount_percent' => $this->percent($data['discount_percent'] ?? null),
                'notes' => $data['notes'] ?? null,
            ];

            $isNew = $order === null;

            if ($order === null) {
                $order = Order::create($attributes + [
                    'reference' => Order::nextReference($business),
                    'status' => OrderStatus::Draft,
                    'created_by' => $by->id,
                ]);
            } else {
                $order->forceFill($attributes)->save();
                $order->lines()->delete();
            }

            foreach ($lines as $position => $line) {
                $order->lines()->create($line + ['position' => $position]);
            }

            $order = $order->fresh()->loadLines();

            activity()
                ->performedOn($order)
                ->causedBy($by)
                ->withProperties([
                    'reference' => $order->reference,
                    'company' => $company->name,
                    'lines' => $order->lines->count(),
                    'total' => $order->total()->toDecimal(),
                    'discount_percent' => $order->discount_percent,
                ])
                ->event($isNew ? 'order.created' : 'order.updated')
                ->log($isNew ? 'Order form written' : 'Order form changed');

            return $order;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  Collection<int, CompanyProduct>  $catalogue
     * @return array<int, array<string, mixed>>
     */
    private function buildLines(array $rows, $catalogue): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $product = isset($row['company_product_id'])
                ? $catalogue->get((int) $row['company_product_id'])
                : null;

            // A line naming a product that is not this company's is dropped
            // rather than written as free text under its name.
            if (isset($row['company_product_id']) && $row['company_product_id'] !== '' && $product === null) {
                continue;
            }

            $brand = $product?->brand_name ?? trim((string) ($row['brand_name'] ?? ''));

            if ($brand === '') {
                continue;
            }

            $caseSize = $product?->case_size ?? $this->integer($row['case_size'] ?? null);
            $cartons = $this->integer($row['cartons'] ?? null);
            $packs = $this->integer($row['packs'] ?? null);

            // Cartons are the convenient way to say it; packs are what the
            // arithmetic runs on, so one is always derived from the other.
            if ($cartons !== null && $caseSize !== null && $caseSize > 0) {
                $packs = $cartons * $caseSize;
            } elseif ($packs !== null && $caseSize !== null && $caseSize > 0 && $packs % $caseSize === 0) {
                $cartons = intdiv($packs, $caseSize);
            } else {
                $cartons = null;
            }

            if ($packs === null || $packs < 1) {
                continue;
            }

            $rate = $row['rate'] ?? null;
            $rate = $rate === null || $rate === ''
                ? ($product?->purchase_rate ?? $product?->trade_price ?? Money::zero())
                : Money::of((string) $rate);

            $lines[] = [
                'company_product_id' => $product?->id,
                'code' => $product?->code ?? null,
                'brand_name' => mb_substr($brand, 0, 200),
                'generic_name' => $product?->generic_name,
                'strength' => $product?->strength,
                'dosage_form' => $product?->dosage_form,
                'pack_size' => $product?->pack_size,
                'case_size' => $caseSize,
                'cartons' => $cartons,
                'packs' => $packs,
                'rate' => $rate,
                'discount_percent' => $this->percent($row['discount_percent'] ?? null),
            ];
        }

        return $lines;
    }

    private function integer(mixed $value): ?int
    {
        $value = trim((string) $value);

        return $value === '' || ! ctype_digit($value) || (int) $value < 1 ? null : (int) $value;
    }

    /** A blank or a zero is "no discount", not "a discount of nothing". */
    private function percent(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return $value <= 0 || $value > 100 ? null : number_format($value, 3, '.', '');
    }
}
