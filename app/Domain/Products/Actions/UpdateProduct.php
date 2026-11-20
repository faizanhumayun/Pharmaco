<?php

namespace App\Domain\Products\Actions;

use App\Exceptions\LedgerException;
use App\Models\CompanyProduct;
use App\Models\PosBillLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Changes a product by hand.
 *
 * Every change is written to the audit trail field by field — what it was,
 * what it became, who changed it — and a change of price also lands in the
 * product's price history, exactly as one arriving on a price list would.
 */
class UpdateProduct
{
    private const TEXT = ['code', 'brand_name', 'generic_name', 'strength', 'dosage_form', 'pack_size', 'pack_type'];

    private const MONEY = ['mrp', 'trade_price', 'purchase_rate'];

    private const WHOLE = ['case_size', 'reorder_level', 'units_per_pack'];

    public function __construct(private readonly RecordProductPrice $prices) {}

    /** @param array<string, mixed> $data */
    public function handle(CompanyProduct $product, array $data, User $by): CompanyProduct
    {
        return DB::transaction(function () use ($product, $data, $by) {
            $before = $this->readable($product);

            foreach (self::TEXT as $field) {
                if (array_key_exists($field, $data)) {
                    $value = trim((string) $data[$field]);
                    $product->{$field} = $value === '' ? null : $value;
                }
            }

            foreach (self::MONEY as $field) {
                if (array_key_exists($field, $data)) {
                    $value = str_replace([',', ' '], '', (string) $data[$field]);
                    $product->{$field} = $value === '' ? null : Money::of($value);
                }
            }

            if (array_key_exists('case_size', $data)) {
                $product->case_size = (int) $data['case_size'] > 0 ? (int) $data['case_size'] : null;
            }

            if (array_key_exists('reorder_level', $data)) {
                $product->reorder_level = max(0, (int) $data['reorder_level']);
            }

            if (array_key_exists('units_per_pack', $data)) {
                $product->units_per_pack = max(1, (int) $data['units_per_pack']);
            }

            if (array_key_exists('is_active', $data)) {
                $product->is_active = (bool) $data['is_active'];
            }

            if ($product->brand_name === null) {
                throw new LedgerException('A product has to have a name.');
            }

            if ($product->mrp !== null && $product->trade_price !== null && $product->trade_price->greaterThan($product->mrp)) {
                throw new LedgerException('The trade price cannot be above the MRP printed on the pack.');
            }

            /*
             * How many pieces are in a pack decides what every loose piece
             * already sold or moved was. Changing it afterwards would silently
             * rewrite stock, so it is fixed once pieces have moved.
             */
            if ($product->isDirty('units_per_pack')) {
                $moved = StockMovement::forBusiness($product->business_id)
                    ->where('company_product_id', $product->id)->where('loose', '!=', 0)->exists()
                    || PosBillLine::forBusiness($product->business_id)
                        ->where('company_product_id', $product->id)->where('loose_quantity', '>', 0)->exists();

                if ($moved) {
                    throw new LedgerException(
                        'Loose pieces of this product have already been sold or moved, so the number in a pack can no longer be changed.'
                    );
                }
            }

            if (! $product->isDirty()) {
                return $product;
            }

            $priceChanged = $product->isDirty(self::MONEY) || $product->isDirty('case_size');

            if ($priceChanged) {
                $product->priced_on = $product->business->today()->toDateString();
            }

            $product->save();

            if ($priceChanged) {
                $this->prices->handle($product, $by, date: $product->business->today());
            }

            $after = $this->readable($product);
            $changed = array_keys(array_diff_assoc($after, $before));

            activity()
                ->performedOn($product)
                ->causedBy($by)
                ->withProperties([
                    'product' => $product->label(),
                    'before' => array_intersect_key($before, array_flip($changed)),
                    'after' => array_intersect_key($after, array_flip($changed)),
                ])
                ->event('product.updated')
                ->log('Product changed by hand');

            return $product->fresh();
        });
    }

    /** @return array<string, string> */
    private function readable(CompanyProduct $product): array
    {
        $row = [];

        foreach (self::TEXT as $field) {
            $row[$field] = (string) ($product->{$field} ?? '');
        }

        foreach (self::MONEY as $field) {
            $row[$field] = $product->{$field}?->toDecimal() ?? '';
        }

        foreach (self::WHOLE as $field) {
            $row[$field] = (string) ($product->{$field} ?? '');
        }

        $row['is_active'] = $product->is_active ? 'yes' : 'no';

        return $row;
    }
}
