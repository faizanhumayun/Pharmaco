<?php

namespace App\Domain\Products;

use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads a PDF into positioned text fragments.
 *
 * Position is what makes a price list readable: the columns are held apart by
 * x coordinates, not by delimiters. Where a file yields no coordinates — a
 * scan, or a producer this library cannot follow — extraction falls back to
 * flat text and says so, and the caller surfaces that as a warning rather than
 * pretending it read a table.
 */
class PdfTextExtractor
{
    /** Pages beyond this are ignored; a price list of this length is already unusual. */
    public const MAX_PAGES = 400;

    /**
     * @return array{fragments: array<int, Fragment>, pages: int, positioned: bool, warnings: array<string>}
     */
    public function extract(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException('The uploaded file could not be read back from storage.');
        }

        $config = new Config;
        $config->setRetainImageContent(false);

        try {
            $document = (new Parser([], $config))->parseFile($path);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'This PDF could not be opened. If it is password protected, remove the password and try again.',
                previous: $e,
            );
        }

        $pages = $document->getPages();

        if ($pages === []) {
            throw new RuntimeException('This PDF has no readable pages.');
        }

        $warnings = [];

        if (count($pages) > self::MAX_PAGES) {
            $warnings[] = 'Only the first '.self::MAX_PAGES.' pages were read.';
            $pages = array_slice($pages, 0, self::MAX_PAGES);
        }

        $fragments = [];
        $positioned = true;

        foreach ($pages as $index => $page) {
            $pageNo = $index + 1;

            try {
                $placed = $page->getDataTm();
            } catch (Throwable) {
                $placed = [];
            }

            if ($placed !== []) {
                foreach ($placed as $item) {
                    $text = trim((string) ($item[1] ?? ''));

                    if ($text === '') {
                        continue;
                    }

                    $fragments[] = new Fragment(
                        page: $pageNo,
                        x: (float) ($item[0][4] ?? 0),
                        y: (float) ($item[0][5] ?? 0),
                        text: $this->clean($text),
                    );
                }

                continue;
            }

            // No coordinates on this page: keep the lines, lose the columns.
            $positioned = false;

            try {
                $text = $page->getText();
            } catch (Throwable) {
                $text = '';
            }

            $y = 10000.0;

            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                if (trim($line) === '') {
                    $y -= 10;

                    continue;
                }

                // Runs of whitespace are the only column hint left, so treat
                // each run as a gap and space the pieces out artificially.
                $x = 0.0;

                foreach (preg_split('/\s{2,}|\t/', trim($line)) ?: [] as $piece) {
                    if (trim($piece) !== '') {
                        $fragments[] = new Fragment($pageNo, $x, $y, $this->clean($piece));
                    }

                    $x += 100.0;
                }

                $y -= 10;
            }
        }

        if ($fragments === []) {
            throw new RuntimeException(
                'No text could be read from this PDF. It is most likely a scan — a PDF of images rather than text.'
            );
        }

        if (! $positioned) {
            $warnings[] = 'Some pages carried no layout information, so their columns were guessed from spacing. Check the rows carefully.';
        }

        return [
            'fragments' => $fragments,
            'pages' => count($pages),
            'positioned' => $positioned,
            'warnings' => $warnings,
        ];
    }

    /** Normalises the punctuation PDFs like to use in place of ASCII. */
    private function clean(string $text): string
    {
        $text = str_replace(
            ["\u{00a0}", "\u{2018}", "\u{2019}", "\u{201c}", "\u{201d}", "\u{2013}", "\u{2014}", "\u{2212}"],
            [' ', "'", "'", '"', '"', '-', '-', '-'],
            $text,
        );

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
