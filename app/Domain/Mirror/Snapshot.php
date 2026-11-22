<?php

namespace App\Domain\Mirror;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The whole of an installation's books, written to one file.
 *
 * A snapshot is every table that holds the books — businesses, people,
 * products, stock, days, bills, the ledger — row for row, as compressed lines
 * of JSON. It is what the shop's computer sends to the platform, and it is
 * equally a backup: {@see SnapshotImporter} puts one back exactly.
 *
 * It copies rather than interprets. Nothing here knows what a ledger entry
 * means, which is the point: the copy cannot disagree with the original
 * because no rule was applied on the way.
 *
 * Reads with the query builder, table by table. That is the one place besides
 * the balance service and the tenant teardown where financial tables are read
 * without their models — and it reads every business on purpose, because it
 * is the installation that is being copied, not a tenant.
 */
class Snapshot
{
    public const VERSION = 1;

    /**
     * Writes a snapshot of a connection to a file.
     *
     * @return array{path: string, tables: int, rows: int, hash: string}
     */
    public function write(string $path, ?string $connection = null): array
    {
        $db = DB::connection($connection);
        $file = gzopen($path, 'wb6');

        if ($file === false) {
            throw new RuntimeException('The snapshot file could not be written: '.$path);
        }

        $hash = hash_init('sha256');
        $tables = 0;
        $rows = 0;

        gzwrite($file, json_encode(['meta' => [
            'version' => self::VERSION,
            'made_at' => now()->toIso8601String(),
            'app' => config('app.name'),
        ]])."\n");

        foreach ($this->tables($connection) as $table) {
            $columns = $db->getSchemaBuilder()->getColumnListing($table);

            $line = json_encode(['table' => $table, 'columns' => $columns])."\n";
            gzwrite($file, $line);
            hash_update($hash, $line);
            $tables++;

            foreach ($db->table($table)->cursor() as $row) {
                $line = json_encode(['row' => array_values((array) $row)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
                gzwrite($file, $line);
                hash_update($hash, $line);
                $rows++;
            }
        }

        gzclose($file);

        // Of the books alone, not of the moment it was made: two snapshots of
        // unchanged books have the same hash, so the second need not be sent.
        return ['path' => $path, 'tables' => $tables, 'rows' => $rows, 'hash' => hash_final($hash)];
    }

    /**
     * The tables that hold the books, in a steady order.
     *
     * @return list<string>
     */
    public function tables(?string $connection = null): array
    {
        $db = DB::connection($connection);

        $all = collect($db->select('select table_name as t from information_schema.tables where table_schema = ? and table_type = ?', [
            $db->getDatabaseName(), 'BASE TABLE',
        ]))->pluck('t')->map(fn ($t) => (string) $t);

        return $all->reject(fn ($t) => in_array($t, config('mirror.skip_tables', []), true))->sort()->values()->all();
    }
}
