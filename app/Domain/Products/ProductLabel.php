<?php

namespace App\Domain\Products;

use App\Enums\DosageForm;

/**
 * How a product is named on screen and on paper.
 *
 * Brand names in a price list often already carry the form — "AZI-ONCE DRY
 * SUSPENSION" — and appending it again reads as a stutter: "AZI-ONCE DRY
 * SUSPENSION 200mg Suspension". So the form is added only when the name has
 * not already said it.
 */
final class ProductLabel
{
    public static function make(?string $brandName, ?string $strength = null, ?string $dosageForm = null): string
    {
        $brand = trim((string) $brandName);
        $parts = array_filter([$brand, trim((string) $strength)]);

        $form = self::formLabel($dosageForm);

        if ($form !== null && ! self::alreadyNamed($brand, $form, $dosageForm)) {
            $parts[] = $form;
        }

        return trim(implode(' ', $parts));
    }

    private static function formLabel(?string $dosageForm): ?string
    {
        $value = trim((string) $dosageForm);

        if ($value === '') {
            return null;
        }

        return DosageForm::tryFrom($value)?->label() ?? $value;
    }

    /**
     * True when the brand name already mentions this form — by its own name, or
     * by any of the abbreviations a price list writes it as ("Tab", "Inj").
     */
    private static function alreadyNamed(string $brand, string $form, ?string $dosageForm): bool
    {
        $haystack = strtolower($brand);

        $needles = [strtolower($form)];

        if ($case = DosageForm::tryFrom(trim((string) $dosageForm))) {
            $needles = array_merge($needles, $case->synonyms());
        }

        foreach (array_unique($needles) as $needle) {
            if ($needle !== '' && preg_match('/\b'.preg_quote($needle, '/').'\b/', $haystack)) {
                return true;
            }
        }

        return false;
    }
}
