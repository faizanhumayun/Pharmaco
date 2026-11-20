<?php

namespace App\Support;

/**
 * Reads how many pieces a printed pack size means.
 *
 *   "1/10", "2\10", "3 x 10"  → strips × tablets: 10, 20, 30
 *   "20's", "30 tab"          → 20, 30
 *   "120ml", "Nos", blank     → 1: a bottle or a single item is not opened
 *
 * The same rule the product form applies as the pack size is typed
 * (piecesFromPack in resources/views/business/products/edit.blade.php). Keep
 * the two in step.
 */
final class PackSize
{
    public static function pieces(?string $text): int
    {
        $text = mb_strtolower(trim((string) $text));

        if (preg_match('/^(\d+)\s*[\/\\\\x×*]\s*(\d+)/u', $text, $m)) {
            return max(1, (int) $m[1] * (int) $m[2]);
        }

        if (preg_match("/^(\d+)\s*('s|s\b|tab|cap|pcs|pieces?)/u", $text, $m)) {
            return max(1, (int) $m[1]);
        }

        return 1;
    }
}
