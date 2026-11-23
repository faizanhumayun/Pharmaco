<?php

namespace App\Domain\Mirror;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The shop's computer taking its books back after a fix.
 *
 * Reads a {@see ReturnPackage}: the rows the back office changed or added are
 * written, the ones it removed are removed, and nothing else is touched.
 * Then the books are checked against what the package says they must now
 * hold — the same number of rows in every table, and the same ledger totals
 * to the paisa, in balance. Only if that holds is any of it kept: all or
 * nothing, in one transaction, so a package that does not fit leaves the
 * books exactly as the shop left them.
 */
class ReturnApplier
{
    /** Tables this computer may write to by itself while it waits; not counted. */
    private const UNCOUNTED = ['devices', 'activity_log'];

    /**
     * @return array{changed: int, removed: int}
     */
    public function apply(string $path): array
    {
        $file = gzopen($path, 'rb');

        if ($file === false) {
            throw new RuntimeException('The package could not be opened.');
        }

        $db = DB::connection();
        $schema = Schema::connection($db->getName());
        $done = ['changed' => 0, 'removed' => 0];

        $db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $db->transaction(function () use ($file, $db, $schema, &$done) {
                $table = null;
                $mode = null;
                $columns = [];
                $ended = false;

                while (($line = gzgets($file)) !== false) {
                    $data = json_decode($line, true);

                    if (! is_array($data)) {
                        throw new RuntimeException('The package is damaged: a line could not be read.');
                    }

                    if (isset($data['meta'])) {
                        if (($data['meta']['version'] ?? null) !== ReturnPackage::VERSION || ($data['meta']['kind'] ?? null) !== 'return') {
                            throw new RuntimeException('This package was made by a different version and cannot be read. Update this computer.');
                        }

                        continue;
                    }

                    if (isset($data['table'])) {
                        $table = $schema->hasTable($data['table']) ? (string) $data['table'] : null;
                        $mode = $data['mode'] ?? 'rows';
                        $columns = $table !== null ? array_flip($schema->getColumnListing($table)) : [];

                        if ($table !== null && $mode === 'replace') {
                            $db->table($table)->delete();
                        }

                        continue;
                    }

                    if (isset($data['end'])) {
                        $this->verify($db, $schema, $data['end']);
                        $ended = true;

                        continue;
                    }

                    if ($table === null) {
                        continue;
                    }

                    if (array_key_exists('del', $data)) {
                        $db->table($table)->where('id', (int) $data['del'])->delete();
                        $done['removed']++;

                        continue;
                    }

                    if (isset($data['put'])) {
                        // Only the columns this computer has: a newer platform may know more.
                        $row = array_intersect_key($data['put'], $columns);

                        if ($mode === 'rows' && isset($row['id']) && $db->table($table)->where('id', $row['id'])->exists()) {
                            $db->table($table)->where('id', $row['id'])->update($row);
                        } else {
                            $db->table($table)->insert($row);
                        }

                        $done['changed']++;
                    }
                }

                if (! $ended) {
                    throw new RuntimeException('The package is incomplete: its end did not arrive.');
                }
            });
        } finally {
            gzclose($file);
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $done;
    }

    /** @param array{counts?: array<string, int>, ledger?: array{debit: string, credit: string}} $end */
    private function verify($db, $schema, array $end): void
    {
        foreach ($end['counts'] ?? [] as $table => $expected) {
            if (in_array($table, self::UNCOUNTED, true) || ! $schema->hasTable($table)) {
                continue;
            }

            $held = $db->table($table)->count();

            if ($held !== (int) $expected) {
                throw new RuntimeException("After the changes {$table} would hold {$held} rows, and it should hold {$expected}. Nothing was changed.");
            }
        }

        if (isset($end['ledger']) && $schema->hasTable('ledger_entries')) {
            $sums = $db->table('ledger_entries')->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')->first();

            if (bccomp((string) $sums->d, (string) $end['ledger']['debit'], 2) !== 0 || bccomp((string) $sums->c, (string) $end['ledger']['credit'], 2) !== 0) {
                throw new RuntimeException('After the changes the ledger would not add up to what was sent. Nothing was changed.');
            }

            if (bccomp((string) $sums->d, (string) $sums->c, 2) !== 0) {
                throw new RuntimeException('After the changes the ledger would not balance. Nothing was changed.');
            }
        }
    }
}
