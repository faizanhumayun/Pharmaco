<?php

namespace App\Domain\Products;

/**
 * Turns positioned fragments into a grid.
 *
 * A price list has no delimiters — its columns exist because every value in
 * them starts at the same x coordinate. So the columns are found by clustering
 * those coordinates across the whole document (the layout repeats on every
 * page) and each fragment is then dropped into the column it starts in.
 * Fragments that share a row and a column are joined, which is what makes a
 * wrapped brand name come back as one cell rather than two.
 */
class TableBuilder
{
    /** Two fragments this close vertically are on the same line. */
    private const ROW_TOLERANCE = 3.0;

    /** Two x coordinates this close belong to the same column. */
    private const COLUMN_TOLERANCE = 6.0;

    /** @param array<int, Fragment> $fragments */
    public function build(array $fragments, int $pageCount): ExtractedTable
    {
        $lines = $this->groupIntoLines($fragments);

        if ($lines === []) {
            return new ExtractedTable([], [], $pageCount);
        }

        $columns = $this->detectColumns($lines);
        $rows = [];
        $line = 0;

        foreach ($lines as $fragmentsOnLine) {
            $cells = array_fill(0, count($columns), '');

            foreach ($fragmentsOnLine as $fragment) {
                $index = $this->columnFor($fragment->x, $columns);
                $cells[$index] = trim($cells[$index].' '.$fragment->text);
            }

            $row = new ExtractedRow($fragmentsOnLine[0]->page, ++$line, $cells);

            if (! $row->isEmpty()) {
                $rows[] = $row;
            }
        }

        return new ExtractedTable($columns, $rows, $pageCount);
    }

    /**
     * @param  array<int, Fragment>  $fragments
     * @return array<int, array<int, Fragment>> each inner array sorted left to right
     */
    private function groupIntoLines(array $fragments): array
    {
        // Page first, then down the page, then across it. PDF y grows upwards,
        // so descending y is top to bottom.
        usort($fragments, function (Fragment $a, Fragment $b) {
            return [$a->page, -$a->y, $a->x] <=> [$b->page, -$b->y, $b->x];
        });

        $lines = [];
        $current = [];
        $currentY = null;
        $currentPage = null;

        foreach ($fragments as $fragment) {
            $sameLine = $currentY !== null
                && $fragment->page === $currentPage
                && abs($fragment->y - $currentY) <= self::ROW_TOLERANCE;

            if (! $sameLine) {
                if ($current !== []) {
                    $lines[] = $current;
                }

                $current = [];
                $currentY = $fragment->y;
                $currentPage = $fragment->page;
            }

            $current[] = $fragment;
        }

        if ($current !== []) {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * @param  array<int, array<int, Fragment>>  $lines
     * @return array<int, float>
     */
    private function detectColumns(array $lines): array
    {
        $positions = [];

        foreach ($lines as $lineIndex => $fragmentsOnLine) {
            foreach ($fragmentsOnLine as $fragment) {
                $positions[] = ['x' => $fragment->x, 'line' => $lineIndex];
            }
        }

        usort($positions, fn (array $a, array $b) => $a['x'] <=> $b['x']);

        // Sweep left to right, opening a new cluster whenever the gap is wider
        // than a column's worth of drift.
        $clusters = [];
        $cluster = null;

        foreach ($positions as $position) {
            if ($cluster === null || $position['x'] - $cluster['last'] > self::COLUMN_TOLERANCE) {
                if ($cluster !== null) {
                    $clusters[] = $cluster;
                }

                $cluster = ['start' => $position['x'], 'last' => $position['x'], 'lines' => []];
            }

            $cluster['last'] = $position['x'];
            $cluster['lines'][$position['line']] = true;
        }

        if ($cluster !== null) {
            $clusters[] = $cluster;
        }

        // A real column appears on most lines of the document. A page number, a
        // footer or a title sits at its own x on two or three lines and would
        // otherwise be read as a column of its own, pushing everything to the
        // right of it out by one. So the bar is set against the busiest column
        // rather than against the page: anything far sparser than the densest
        // column is folded into the column to its left instead.
        $busiest = 0;

        foreach ($clusters as $candidate) {
            $busiest = max($busiest, count($candidate['lines']));
        }

        $threshold = max(3, (int) ceil($busiest * 0.15));

        $kept = array_values(array_filter(
            $clusters,
            fn (array $c) => count($c['lines']) >= $threshold,
        ));

        if ($kept === []) {
            $kept = $clusters;
        }

        return array_map(fn (array $c) => round($c['start'], 2), $kept);
    }

    /** @param array<int, float> $columns */
    private function columnFor(float $x, array $columns): int
    {
        $index = 0;

        foreach ($columns as $i => $columnX) {
            if ($x + self::COLUMN_TOLERANCE >= $columnX) {
                $index = $i;

                continue;
            }

            break;
        }

        return $index;
    }
}
