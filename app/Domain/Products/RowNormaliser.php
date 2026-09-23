<?php

namespace App\Domain\Products;

use App\Enums\DosageForm;
use App\Enums\PackType;
use App\Enums\ProductField;
use App\Support\Money;

/**
 * Turns one extracted row into the values a product would be built from,
 * together with everything wrong with it.
 *
 * Nothing is repaired silently. A cell that cannot be read as a price becomes
 * an error against that row, and the row waits on the review screen until a
 * person fixes it or excludes it. A row that is merely odd — a trade price
 * above the MRP — is imported with a warning, because odd is not the same as
 * wrong and the company's list is the company's list.
 */
class RowNormaliser
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /**
     * @return array{values: array<string, string|null>, issues: array<int, array<string, string>>, included: bool}
     */
    public function normalise(ColumnMap $map, ExtractedRow $row): array
    {
        $raw = [];

        foreach (ProductField::cases() as $field) {
            $column = $map->columnFor($field);
            $raw[$field->value] = $column === null ? '' : $row->cell($column);
        }

        return $this->fromValues($raw, $row);
    }

    /**
     * The same rules applied to values a person typed on the review screen, so
     * an edited row is judged exactly as a parsed one is.
     *
     * @param  array<string, string|null>  $raw
     * @return array{values: array<string, string|null>, issues: array<int, array<string, string>>, included: bool}
     */
    public function fromValues(array $raw, ?ExtractedRow $row = null): array
    {
        $values = [];
        $issues = [];

        $brand = $this->text($raw[ProductField::BrandName->value] ?? '', 200);

        // One "Description" column often carries the lot: "Brufen 400mg Tab 2x10".
        // What the columns did not give, the name is asked for.
        $derived = $brand === null ? [] : $this->deriveFromName($brand);

        $values['code'] = $this->text($raw['code'] ?? '', 60);
        $values['brand_name'] = $brand;
        $values['generic_name'] = $this->text($raw['generic_name'] ?? '', 255);
        $values['strength'] = $this->text($raw['strength'] ?? '', 80) ?? ($derived['strength'] ?? null);
        $values['pack_size'] = $this->packSize($raw['pack_size'] ?? '') ?? ($derived['pack_size'] ?? null);

        [$values['dosage_form'], $formIssue] = $this->enumValue(
            $raw['dosage_form'] ?? '',
            fn (string $v) => DosageForm::match($v)?->value,
            'dosage form',
        );

        $values['dosage_form'] ??= $derived['dosage_form'] ?? null;

        [$values['pack_type'], $packIssue] = $this->enumValue(
            $raw['pack_type'] ?? '',
            fn (string $v) => PackType::match($v)?->value,
            'pack type',
        );

        foreach (array_filter([$formIssue, $packIssue]) as $issue) {
            $issues[] = $issue;
        }

        foreach ([ProductField::Mrp, ProductField::TradePrice, ProductField::PurchaseRate] as $field) {
            $cell = trim((string) ($raw[$field->value] ?? ''));
            $number = Text::number($cell);

            if ($cell !== '' && $number === null) {
                $issues[] = [
                    'field' => $field->value,
                    'level' => self::ERROR,
                    'message' => "\"{$cell}\" is not a price.",
                ];
                $values[$field->value] = null;

                continue;
            }

            if ($number !== null && str_starts_with($number, '-')) {
                $issues[] = [
                    'field' => $field->value,
                    'level' => self::ERROR,
                    'message' => 'A price cannot be negative.',
                ];
                $values[$field->value] = null;

                continue;
            }

            $values[$field->value] = $number === null ? null : Money::of($number)->toDecimal();
        }

        $caseCell = trim((string) ($raw['case_size'] ?? ''));
        $values['case_size'] = null;

        if ($caseCell !== '') {
            $number = Text::number($caseCell);

            if ($number === null || (float) $number <= 0 || str_contains($number, '.')) {
                $issues[] = [
                    'field' => 'case_size',
                    'level' => self::ERROR,
                    'message' => "\"{$caseCell}\" is not a whole number of packs.",
                ];
            } else {
                $values['case_size'] = (string) (int) $number;
            }
        }

        if ($brand === null) {
            $issues[] = [
                'field' => 'brand_name',
                'level' => self::ERROR,
                'message' => $row !== null && $row->filledCount() <= 2
                    ? 'No product name — this line reads as a heading rather than a product.'
                    : 'No product name.',
            ];
        }

        // A company name, a date, a section title: one cell of text on a line
        // in a table that is otherwise full of figures. It parses as a product
        // with a name and nothing else, which is exactly what it is not — so it
        // is set aside with the reason shown, for a person to overrule.
        $bareHeading = $brand !== null
            && $row !== null
            && $row->filledCount() <= 1
            && $values['mrp'] === null
            && $values['trade_price'] === null
            && $values['purchase_rate'] === null;

        if ($bareHeading) {
            $issues[] = [
                'field' => 'brand_name',
                'level' => self::WARNING,
                'message' => 'This line carries a name and no prices, so it reads as a heading rather than a product.',
            ];
        }

        foreach ($this->sanityWarnings($values) as $warning) {
            $issues[] = $warning;
        }

        return [
            'values' => $values,
            'issues' => $issues,
            // A row with an error is kept out of the import until it is fixed;
            // it is never dropped, so nothing disappears without being seen.
            'included' => ! $this->hasError($issues) && ! $bareHeading,
        ];
    }

    /** @param array<int, array<string, string>> $issues */
    public function hasError(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['level'] ?? '') === self::ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<int, array<string, string>>
     */
    private function sanityWarnings(array $values): array
    {
        $warnings = [];

        $mrp = $values['mrp'] ?? null;
        $trade = $values['trade_price'] ?? null;
        $rate = $values['purchase_rate'] ?? null;

        if ($mrp !== null && $trade !== null && Money::of($trade)->greaterThan(Money::of($mrp))) {
            $warnings[] = [
                'field' => 'trade_price',
                'level' => self::WARNING,
                'message' => 'Trade price is above the MRP.',
            ];
        }

        if ($trade !== null && $rate !== null && Money::of($rate)->greaterThan(Money::of($trade))) {
            $warnings[] = [
                'field' => 'purchase_rate',
                'level' => self::WARNING,
                'message' => 'Our rate is above the trade price.',
            ];
        }

        return $warnings;
    }

    /**
     * Pulls strength, form and pack size out of a product name.
     *
     * @return array<string, string>
     */
    private function deriveFromName(string $name): array
    {
        $derived = [];

        if (preg_match('/\b(\d+(?:\.\d+)?\s*(?:mg|mcg|ug|g|ml|l|iu|%)(?:\s*\/\s*\d+(?:\.\d+)?\s*(?:mg|ml|g))?)\b/i', $name, $m)) {
            $derived['strength'] = preg_replace('/\s+/', '', $m[1]) ?? $m[1];
        }

        if (preg_match('/\b(\d+\s*[x×]\s*\d+(?:\s*[x×]\s*\d+)?)\b/iu', $name, $m)) {
            $derived['pack_size'] = strtolower(preg_replace('/\s+/', '', str_replace('×', 'x', $m[1])) ?? $m[1]);
        }

        if ($form = DosageForm::match($name)) {
            $derived['dosage_form'] = $form->value;
        }

        return $derived;
    }

    private function text(?string $value, int $limit): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
        $value = trim($value, " \t\n\r\0\x0B.-–—|:;");

        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }

    private function packSize(?string $value): ?string
    {
        $value = $this->text($value, 60);

        if ($value === null) {
            return null;
        }

        // "2 X 10", "2×10" and "2x10" are the same pack.
        $value = str_replace('×', 'x', $value);

        return preg_replace('/(\d)\s*[xX]\s*(\d)/', '$1x$2', $value) ?? $value;
    }

    /**
     * @param  callable(string): ?string  $matcher
     * @return array{0: ?string, 1: ?array<string, string>}
     */
    private function enumValue(?string $cell, callable $matcher, string $label): array
    {
        $cell = trim((string) $cell);

        if ($cell === '') {
            return [null, null];
        }

        $matched = $matcher($cell);

        if ($matched !== null) {
            return [$matched, null];
        }

        // Kept as written, flagged as unfamiliar. Refusing the row over a form
        // this system has not met before would be the wrong trade.
        return [mb_substr(strtolower($cell), 0, 40), [
            'field' => $label === 'dosage form' ? 'dosage_form' : 'pack_type',
            'level' => self::WARNING,
            'message' => "\"{$cell}\" is not a {$label} this system recognises — kept as written.",
        ]];
    }
}
