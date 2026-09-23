<?php

namespace App\Domain\Products;

use App\Enums\ProductField;

/**
 * Finds the header row and works out what each column holds.
 *
 * Nothing here maps by position: a column means what its heading says it
 * means. Where a heading is missing or unrecognised the column is left unmapped
 * for a person to assign, which is a great deal safer than assuming the fourth
 * column is always the trade price.
 */
class HeaderMatcher
{
    /** How far into the document to look for a header. */
    private const SEARCH_DEPTH = 80;

    /**
     * @return array{map: ColumnMap, headerLines: array<int, int>, score: int}
     */
    public function match(ExtractedTable $table): array
    {
        $best = ['map' => new ColumnMap, 'headerLines' => [], 'score' => 0];

        $rows = array_slice($table->rows, 0, self::SEARCH_DEPTH);

        foreach ($rows as $index => $row) {
            // A heading split over two lines — "Trade" above "Price" — reads as
            // one heading once the lines are laid on top of each other.
            $candidates = [[$row->cells, [$row->line]]];

            if (isset($rows[$index + 1]) && $rows[$index + 1]->page === $row->page) {
                $next = $rows[$index + 1];
                $joined = [];

                foreach ($row->cells as $column => $cell) {
                    $joined[$column] = trim($cell.' '.($next->cells[$column] ?? ''));
                }

                $candidates[] = [$joined, [$row->line, $next->line]];
            }

            foreach ($candidates as [$cells, $lines]) {
                $scored = $this->score($cells);

                if ($scored['score'] > $best['score']) {
                    $best = [
                        'map' => $scored['map'],
                        'headerLines' => $lines,
                        'score' => $scored['score'],
                    ];
                }
            }
        }

        // Two matched headings is the floor. One is far more likely to be a
        // stray word in the title block than a real table header.
        if ($best['score'] < 5) {
            return ['map' => new ColumnMap, 'headerLines' => [], 'score' => 0];
        }

        $best['headerLines'] = $this->includeRepeats($table, $best['headerLines']);

        return $best;
    }

    /**
     * @param  array<int, string>  $cells
     * @return array{map: ColumnMap, score: int}
     */
    private function score(array $cells): array
    {
        $claims = [];

        foreach ($cells as $column => $cell) {
            $heading = Text::normaliseHeader($cell);

            if ($heading === '' || strlen($heading) > 40) {
                continue;
            }

            $bestField = null;
            $bestScore = 0;

            foreach (ProductField::cases() as $field) {
                foreach ($field->synonyms() as $synonym) {
                    $score = $this->compare($heading, $synonym);

                    // A longer synonym matching is more telling than a short
                    // one: "trade price" beats "price" buried in "trade price".
                    if ($score > 0) {
                        $score += min(strlen($synonym), 20) / 100;
                    }

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestField = $field;
                    }
                }
            }

            if ($bestField !== null) {
                $claims[$column] = ['field' => $bestField, 'score' => $bestScore];
            }
        }

        // One field, one column: where two headings claim the same field the
        // stronger match keeps it and the other column is left unmapped.
        $winners = [];

        foreach ($claims as $column => $claim) {
            $field = $claim['field']->value;

            if (! isset($winners[$field]) || $claim['score'] > $winners[$field]['score']) {
                $winners[$field] = ['column' => $column, 'score' => $claim['score'], 'field' => $claim['field']];
            }
        }

        $map = new ColumnMap;
        $total = 0.0;

        foreach ($winners as $winner) {
            $map->set($winner['column'], $winner['field']);
            $total += $winner['score'];
        }

        // A price list without a name column is not a price list.
        if (! $map->has(ProductField::BrandName)) {
            $total = 0.0;
        }

        return ['map' => $map, 'score' => (int) round($total)];
    }

    private function compare(string $heading, string $synonym): float
    {
        if ($heading === $synonym) {
            return 3.0;
        }

        if (preg_match('/\b'.preg_quote($synonym, '/').'\b/', $heading)) {
            return strlen($synonym) >= 3 ? 2.0 : 0.0;
        }

        return 0.0;
    }

    /**
     * Headers repeat at the top of every page. They are found by text rather
     * than by position, so a page that starts higher or lower is still caught.
     *
     * @param  array<int, int>  $lines
     * @return array<int, int>
     */
    private function includeRepeats(ExtractedTable $table, array $lines): array
    {
        $signatures = [];

        foreach ($table->rows as $row) {
            if (in_array($row->line, $lines, true)) {
                $signatures[Text::normalise($row->text())] = true;
            }
        }

        foreach ($table->rows as $row) {
            if (isset($signatures[Text::normalise($row->text())])) {
                $lines[] = $row->line;
            }
        }

        return array_values(array_unique($lines));
    }
}
