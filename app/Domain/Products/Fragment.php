<?php

namespace App\Domain\Products;

/** One run of text the PDF placed at a known position on a page. */
final readonly class Fragment
{
    public function __construct(
        public int $page,
        public float $x,
        public float $y,
        public string $text,
    ) {}
}
