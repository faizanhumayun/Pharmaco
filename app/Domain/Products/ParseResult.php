<?php

namespace App\Domain\Products;

final readonly class ParseResult
{
    /**
     * @param  array<int, ExtractedRow>  $rows  data rows, headers removed
     * @param  array<int, int>  $guessed  columns the header did not name
     * @param  array<string>  $warnings
     */
    public function __construct(
        public ExtractedTable $table,
        public array $rows,
        public ColumnMap $map,
        public array $guessed,
        public bool $headerFound,
        public int $pageCount,
        public array $warnings = [],
    ) {}
}
