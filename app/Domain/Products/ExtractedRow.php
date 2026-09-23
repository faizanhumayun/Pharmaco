<?php

namespace App\Domain\Products;

/** A line of the PDF, split into the columns the document uses. */
final readonly class ExtractedRow
{
    /** @param array<int, string> $cells */
    public function __construct(
        public int $page,
        public int $line,
        public array $cells,
    ) {}

    public function cell(int $index): string
    {
        return trim($this->cells[$index] ?? '');
    }

    public function isEmpty(): bool
    {
        return trim(implode('', $this->cells)) === '';
    }

    public function text(): string
    {
        return trim(preg_replace('/\s+/', ' ', implode(' ', $this->cells)) ?? '');
    }

    /** How many cells carry something other than whitespace. */
    public function filledCount(): int
    {
        return count(array_filter($this->cells, fn (string $c) => trim($c) !== ''));
    }
}
