<?php

namespace App\Domain\Products;

/** The whole PDF reduced to a grid: a fixed set of columns and the rows in them. */
final readonly class ExtractedTable
{
    /**
     * @param  array<int, float>  $columns  left edge of each column, in order
     * @param  array<int, ExtractedRow>  $rows
     * @param  array<string>  $warnings
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public int $pageCount,
        public array $warnings = [],
    ) {}

    public function columnCount(): int
    {
        return count($this->columns);
    }
}
