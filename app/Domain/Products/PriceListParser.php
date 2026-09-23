<?php

namespace App\Domain\Products;

use App\Enums\ProductField;

/**
 * Reads a company's price-list PDF into candidate rows.
 *
 * The parser proposes; it never decides. What comes back is a table, a column
 * map and an honest account of what it was unsure about — which the review
 * screen then puts in front of a person before a single product is written.
 */
class PriceListParser
{
    public function __construct(
        private readonly PdfTextExtractor $extractor,
        private readonly TableBuilder $builder,
        private readonly HeaderMatcher $headers,
        private readonly ContentGuesser $guesser,
    ) {}

    public function parse(string $path): ParseResult
    {
        $extracted = $this->extractor->extract($path);

        $table = $this->builder->build($extracted['fragments'], $extracted['pages']);
        $warnings = [...$extracted['warnings'], ...$table->warnings];

        $header = $this->headers->match($table);
        $map = $header['map'];
        $headerLines = array_flip($header['headerLines']);

        $rows = array_values(array_filter(
            $table->rows,
            fn (ExtractedRow $row) => ! isset($headerLines[$row->line]),
        ));

        $guessed = $this->guesser->fill($map, $rows, $table->columnCount());

        if ($header['headerLines'] === []) {
            $warnings[] = 'No column headings were found, so every column was worked out from its contents. Check the mapping before importing.';
        } elseif ($guessed !== []) {
            $warnings[] = count($guessed) === 1
                ? 'One column had no heading this system recognised and was worked out from its contents.'
                : count($guessed).' columns had no heading this system recognised and were worked out from their contents.';
        }

        if (! $map->has(ProductField::BrandName)) {
            $warnings[] = 'No column could be identified as the product name. Assign one below — nothing can be imported without it.';
        }

        if ($rows === []) {
            $warnings[] = 'No rows were found beneath the headings.';
        }

        return new ParseResult(
            table: $table,
            rows: $rows,
            map: $map,
            guessed: $guessed,
            headerFound: $header['headerLines'] !== [],
            pageCount: $extracted['pages'],
            warnings: array_values(array_unique($warnings)),
        );
    }
}
