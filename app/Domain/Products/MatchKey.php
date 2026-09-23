<?php

namespace App\Domain\Products;

/**
 * How a re-imported list recognises a product already in the catalogue.
 *
 * The company's own item code is used when the list gives one, because that is
 * the company's own identity for the product and survives a renamed brand.
 * Otherwise the brand, strength and pack size together are the identity —
 * "Panadol 500mg 10x10" and "Panadol 500mg 2x10" are different products and
 * have to stay different, or one import will overwrite the other's price.
 */
final class MatchKey
{
    public static function make(
        ?string $code,
        ?string $brandName,
        ?string $strength = null,
        ?string $packSize = null,
    ): string {
        $code = self::flatten((string) $code);

        if ($code !== '') {
            return self::fit('code:'.$code);
        }

        $parts = array_map(self::flatten(...), [(string) $brandName, (string) $strength, (string) $packSize]);

        return self::fit(implode('|', $parts));
    }

    private static function flatten(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }

    /** Stays inside the unique index's 191 characters without losing distinctness. */
    private static function fit(string $key): string
    {
        if (strlen($key) <= 191) {
            return $key;
        }

        return substr($key, 0, 150).'#'.substr(sha1($key), 0, 16);
    }
}
