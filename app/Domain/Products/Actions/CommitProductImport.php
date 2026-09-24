<?php

namespace App\Domain\Products\Actions;

use App\Domain\Products\MatchKey;
use App\Enums\ImportStatus;
use App\Enums\ProductField;
use App\Models\CompanyProduct;
use App\Models\CompanyProductImport;
use App\Models\CompanyProductImportRow;
use App\Models\CompanyProductPrice;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies a reviewed import to the company's catalogue.
 *
 * Two rules decide everything here. A new list adds what it knows and does not
 * erase what it does not mention — a list without generic names must not wipe
 * the generic names already recorded. And every price it changes is written to
 * the history before the catalogue is updated, so the old figure survives the
 * new one.
 *
 * All of it happens in one transaction: an import either lands completely or
 * leaves the catalogue exactly as it was.
 */
class CommitProductImport
{
    public function __construct(private readonly RecordProductPrice $prices) {}

    /** Fields a later list may refresh on a product it has seen before. */
    private const DESCRIPTIVE = [
        'code', 'brand_name', 'generic_name', 'strength', 'dosage_form', 'pack_size', 'pack_type',
    ];

    private const PRICED = ['mrp', 'trade_price', 'purchase_rate', 'case_size'];

    public function handle(CompanyProductImport $import, User $by): CompanyProductImport
    {
        if (! $import->isEditable()) {
            throw new RuntimeException('This import has already been applied.');
        }

        if (! $import->map()->has(ProductField::BrandName)) {
            throw new RuntimeException('No column is mapped to the product name, so there is nothing to import.');
        }

        return DB::transaction(function () use ($import, $by) {
            $created = 0;
            $updated = 0;
            $unchanged = 0;
            $duplicates = 0;

            // Every product this company already has, by the key a repeat
            // listing would be recognised under.
            $existing = CompanyProduct::forBusiness($import->business_id)
                ->where('company_id', $import->company_id)
                ->get()
                ->keyBy('match_key');

            $seen = [];

            $rows = CompanyProductImportRow::query()
                ->where('company_product_import_id', $import->id)
                ->where('included', true)
                ->orderBy('page_no')->orderBy('line_no');

            foreach ($rows->cursor() as $row) {
                $values = $row->values ?? [];
                $brand = $values['brand_name'] ?? null;

                if ($brand === null || $brand === '') {
                    continue;
                }

                $key = MatchKey::make(
                    $values['code'] ?? null,
                    $brand,
                    $values['strength'] ?? null,
                    $values['pack_size'] ?? null,
                );

                // The same product listed twice in one file: the first listing
                // stands, so a stray repeat cannot quietly restate a price.
                if (isset($seen[$key])) {
                    $duplicates++;

                    continue;
                }

                $seen[$key] = true;

                $product = $existing->get($key);

                if ($product === null) {
                    $product = new CompanyProduct([
                        'business_id' => $import->business_id,
                        'company_id' => $import->company_id,
                        'match_key' => $key,
                        'brand_name' => $brand,
                        'is_active' => true,
                        'first_import_id' => $import->id,
                    ]);

                    $this->applyDescriptive($product, $values);
                    $this->applyPriced($product, $values);

                    $product->last_import_id = $import->id;
                    $product->priced_on = $import->business_date;
                    $product->save();

                    $this->recordPrice($import, $product, $by);
                    $existing->put($key, $product);
                    $created++;

                    continue;
                }

                $before = $this->pricedSnapshot($product);

                $this->applyDescriptive($product, $values);
                $this->applyPriced($product, $values);

                $pricesMoved = $before !== $this->pricedSnapshot($product);

                if ($pricesMoved) {
                    $product->priced_on = $import->business_date;
                }

                $changed = $product->isDirty();
                $product->last_import_id = $import->id;
                $product->save();

                if ($pricesMoved) {
                    $this->recordPrice($import, $product, $by);
                }

                $changed ? $updated++ : $unchanged++;
            }

            $skipped = max(0, (int) $import->rows_detected - ($created + $updated + $unchanged));

            activity()
                ->performedOn($import)
                ->causedBy($by)
                ->withProperties([
                    'file' => $import->original_filename,
                    'company' => $import->company->name,
                    'created' => $created,
                    'updated' => $updated,
                    'unchanged' => $unchanged,
                    'skipped' => $skipped,
                ])
                ->event('price_list.applied')
                ->log('Price list applied');

            $import->forceFill([
                'status' => ImportStatus::Committed,
                'products_created' => $created,
                'products_updated' => $updated,
                'products_unchanged' => $unchanged,
                'rows_skipped' => $skipped,
                'committed_at' => now(),
            ])->save();

            return $import->fresh();
        });
    }

    /** @param array<string, mixed> $values */
    private function applyDescriptive(CompanyProduct $product, array $values): void
    {
        foreach (self::DESCRIPTIVE as $field) {
            $value = $values[$field] ?? null;

            // Silence is not a correction: a list that omits a field leaves
            // whatever is already recorded alone.
            if ($value !== null && $value !== '') {
                $product->{$field} = $value;
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function applyPriced(CompanyProduct $product, array $values): void
    {
        foreach (self::PRICED as $field) {
            $value = $values[$field] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $product->{$field} = $field === 'case_size' ? (int) $value : Money::of((string) $value);
        }
    }

    /** @return array<string, string|null> */
    private function pricedSnapshot(CompanyProduct $product): array
    {
        return [
            'mrp' => $product->mrp?->toDecimal(),
            'trade_price' => $product->trade_price?->toDecimal(),
            'purchase_rate' => $product->purchase_rate?->toDecimal(),
            'case_size' => $product->case_size === null ? null : (string) $product->case_size,
        ];
    }

    private function recordPrice(CompanyProductImport $import, CompanyProduct $product, User $by): void
    {
        // One place writes a price down, whichever way the price arrived.
        $this->prices->handle($product, $by, $import);
    }
}
