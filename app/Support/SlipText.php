<?php

namespace App\Support;

/**
 * The few lines a business writes for the foot of its bill.
 *
 * Written in a small editor, so it arrives as HTML — and HTML typed by a user
 * is printed on every bill, so it is cut down to the handful of things the
 * editor offers: line breaks, bullet points, bold, italic and underline. Every other tag goes,
 * and every attribute goes with no exceptions, which is what keeps a script or
 * an event handler from ever reaching a page.
 */
final class SlipText
{
    private const ALLOWED = '<b><strong><i><em><u><br><div><p><ul><ol><li>';

    public const MAX_LENGTH = 1000;

    /** Null when nothing is left worth printing. */
    public static function clean(?string $html): ?string
    {
        $html = strip_tags((string) $html, self::ALLOWED);

        // No attributes at all: <b onclick="…"> becomes <b>.
        $html = preg_replace('/<(\/?)([a-zA-Z]+)[^>]*>/', '<$1$2>', $html) ?? '';

        // What a browser's editor leaves behind for an empty line.
        $html = trim(preg_replace('/(<div><br><\/div>|<p><br><\/p>|<br>|\s|&nbsp;)+$/', '', $html) ?? '');

        // Bullets with nothing in them.
        $html = preg_replace('/<li>(\s|&nbsp;|<br>)*<\/li>/', '', $html) ?? '';
        $html = preg_replace('/<(ul|ol)>\s*<\/\1>/', '', $html) ?? '';

        if (trim(html_entity_decode(strip_tags($html))) === '') {
            return null;
        }

        return mb_substr($html, 0, self::MAX_LENGTH);
    }
}
