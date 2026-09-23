<?php

namespace App\Domain\Products;

/** Small string helpers shared by the header matcher and the row normaliser. */
final class Text
{
    /** Lowercase, punctuation to spaces, whitespace collapsed. */
    public static function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9%\/.]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /** The same, but with the trailing units and punctuation of a header removed. */
    public static function normaliseHeader(string $value): string
    {
        $value = self::normalise($value);
        $value = preg_replace('/\b(rs|pkr|rupees|price in rs|in rs|amount)\b/', '', $value) ?? $value;
        $value = str_replace(['.', '/'], ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * Reads a number out of a cell, or null if there isn't one.
     *
     * Price lists write figures as "1,234.50", "1234/50", "Rs. 890", "890.00*"
     * and occasionally "(12.00)" for a negative. Anything still ambiguous after
     * this comes back as null and is shown to a person.
     */
    public static function number(?string $value): ?string
    {
        $raw = trim((string) $value);

        if ($raw === '' || $raw === '-' || $raw === '—') {
            return null;
        }

        $negative = str_starts_with($raw, '(') && str_ends_with($raw, ')');

        $cleaned = str_replace([',', ' ', 'Rs.', 'Rs', 'rs.', 'rs', 'PKR', 'pkr', '*', '(', ')'], '', $raw);

        // "1234/50" is how some lists write rupees and paisa.
        $cleaned = preg_replace('#^(\d+)/(\d{1,2})$#', '$1.$2', $cleaned) ?? $cleaned;

        // Trailing marks like "890.00+" or "125.00/-".
        $cleaned = rtrim($cleaned, '+-/=');

        if (! preg_match('/^\d+(\.\d+)?$/', $cleaned)) {
            return null;
        }

        return $negative ? '-'.$cleaned : $cleaned;
    }

    /** True when the cell looks like a figure rather than a word. */
    public static function looksNumeric(string $value): bool
    {
        return self::number($value) !== null;
    }

    /**
     * True when the cell looks like a price rather than a count.
     *
     * The decimal part is the whole test. "48" is a carton of forty-eight, not
     * forty-eight rupees, and guessing otherwise puts a packing figure in a
     * price column — so a bare whole number is never taken for money here.
     */
    public static function looksLikeMoney(string $value): bool
    {
        $number = self::number($value);

        return $number !== null && str_contains($number, '.');
    }
}
