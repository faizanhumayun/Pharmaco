<?php

namespace App\Domain\Products;

use App\Enums\DosageForm;
use App\Enums\PackType;
use App\Enums\ProductField;

/**
 * Proposes a field for columns the header did not name.
 *
 * Every proposal here is a guess from what the column contains, so each one is
 * reported back and shown on the review screen as a guess. The operator
 * confirms or changes it; nothing is imported on the strength of a guess alone.
 */
class ContentGuesser
{
    /**
     * @param  array<int, ExtractedRow>  $rows  data rows, headers already removed
     * @return array<int, int> the columns this guesser filled in
     */
    public function fill(ColumnMap $map, array $rows, int $columnCount): array
    {
        if ($rows === []) {
            return [];
        }

        $stats = [];

        for ($column = 0; $column < $columnCount; $column++) {
            $stats[$column] = $this->profile($rows, $column);
        }

        $guessed = [];
        $taken = fn (int $column) => $map->fieldFor($column) !== null;

        // Forms and strengths are recognisable from their own vocabulary, but
        // only in a column that holds nothing else. "Ciproxin 500mg Tab 2x10"
        // contains a form and a strength and is neither — it is the product
        // name — so a column of long values is not eligible for either.
        foreach ([
            [ProductField::DosageForm, fn (array $s) => $s['formRate'] >= 0.5 && $s['words'] <= 2.5 && $s['length'] <= 24],
            [ProductField::PackType, fn (array $s) => $s['packTypeRate'] >= 0.5 && $s['words'] <= 2.5 && $s['length'] <= 24],
            [ProductField::Strength, fn (array $s) => $s['strengthRate'] >= 0.5 && $s['words'] <= 2.0 && $s['length'] <= 16],
        ] as [$field, $test]) {
            if ($map->has($field)) {
                continue;
            }

            foreach ($stats as $column => $profile) {
                if (! $taken($column) && $profile['filled'] >= 0.5 && $test($profile)) {
                    $map->set($column, $field);
                    $guessed[] = $column;

                    break;
                }
            }
        }

        // Whatever reads as words is a name. The leftmost is the brand — that
        // is the order every price list in this trade is written in — and the
        // next one along is the generic.
        $textColumns = [];

        foreach ($stats as $column => $profile) {
            if (! $taken($column) && $profile['filled'] >= 0.6 && $profile['numericRate'] <= 0.3) {
                $textColumns[$column] = true;
            }
        }

        foreach ([ProductField::BrandName, ProductField::GenericName] as $field) {
            if ($map->has($field) || $textColumns === []) {
                continue;
            }

            $column = array_key_first($textColumns);
            unset($textColumns[$column]);

            $map->set($column, $field);
            $guessed[] = $column;
        }

        // A carton count before the prices: whole numbers with no decimal part
        // are a quantity, and claiming one as a price is the costlier mistake.
        if (! $map->has(ProductField::CaseSize)) {
            foreach ($stats as $column => $profile) {
                if (! $taken($column) && $profile['filled'] >= 0.5
                    && $profile['numericRate'] >= 0.8 && $profile['decimalRate'] < 0.1
                    && $profile['average'] > 0 && $profile['average'] <= 5000) {
                    $map->set($column, ProductField::CaseSize);
                    $guessed[] = $column;

                    break;
                }
            }
        }

        // The prices. In this trade MRP is above trade price, which is above
        // the rate a distributor is billed, so unclaimed money columns sort
        // themselves by size. Fewer columns than fields simply means the list
        // does not quote the last of them.
        $moneyColumns = [];

        foreach ($stats as $column => $profile) {
            if (! $taken($column) && $profile['moneyRate'] >= 0.7) {
                $moneyColumns[$column] = $profile['average'];
            }
        }

        $wanted = array_values(array_filter(
            [ProductField::Mrp, ProductField::TradePrice, ProductField::PurchaseRate],
            fn (ProductField $f) => ! $map->has($f),
        ));

        arsort($moneyColumns);

        foreach (array_keys($moneyColumns) as $position => $column) {
            if (! isset($wanted[$position])) {
                break;
            }

            $map->set($column, $wanted[$position]);
            $guessed[] = $column;
        }

        return array_values(array_unique($guessed));
    }

    /**
     * @param  array<int, ExtractedRow>  $rows
     * @return array<string, float|int>
     */
    private function profile(array $rows, int $column): array
    {
        $sample = array_slice($rows, 0, 300);
        $total = max(count($sample), 1);

        $filled = 0;
        $numeric = 0;
        $money = 0;
        $decimal = 0;
        $form = 0;
        $packType = 0;
        $strength = 0;
        $sum = 0.0;
        $length = 0;
        $words = 0;
        $values = [];

        foreach ($sample as $row) {
            $value = $row->cell($column);

            if ($value === '') {
                continue;
            }

            $filled++;
            $values[$value] = true;
            $length += mb_strlen($value);
            $words += count(preg_split('/\s+/', $value) ?: []);

            $number = Text::number($value);

            if ($number !== null) {
                $numeric++;
                $sum += (float) $number;

                if (str_contains($number, '.')) {
                    $decimal++;
                }

                if (Text::looksLikeMoney($value)) {
                    $money++;
                }

                continue;
            }

            if (DosageForm::match($value) !== null) {
                $form++;
            }

            if (PackType::match($value) !== null) {
                $packType++;
            }

            if (preg_match('/\d+\s*(mg|mcg|ug|g|ml|l|iu|%)\b/i', $value)) {
                $strength++;
            }
        }

        $filledOrOne = max($filled, 1);

        return [
            'filled' => $filled / $total,
            'numericRate' => $numeric / $filledOrOne,
            'moneyRate' => $money / $filledOrOne,
            'decimalRate' => $numeric > 0 ? $decimal / $numeric : 0.0,
            'formRate' => $form / $filledOrOne,
            'packTypeRate' => $packType / $filledOrOne,
            'strengthRate' => $strength / $filledOrOne,
            'average' => $numeric > 0 ? $sum / $numeric : 0.0,
            'length' => $length / $filledOrOne,
            'words' => $words / $filledOrOne,
            'distinct' => count($values),
        ];
    }
}
