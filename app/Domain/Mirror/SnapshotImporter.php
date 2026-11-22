<?php

namespace App\Domain\Mirror;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Puts a {@see Snapshot} into a database, replacing what was there.
 *
 * Used for two things: on the platform, to refresh a shop's read-only copy
 * each time the shop sends one; and on a shop's computer, to restore the books
 * from a backup.
 *
 * All or nothing. The rows are replaced inside one transaction, so a snapshot
 * that turns out to be damaged half way leaves the previous copy exactly as
 * it was. Tables are emptied with DELETE rather than TRUNCATE for that reason:
 * TRUNCATE cannot be undone.
 */
class SnapshotImporter
{
    /**
     * @return array{tables: int, rows: int, made_at: ?string}
     */
    public function import(string $path, string $connection): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('The snapshot file is not there: '.$path);
        }

        // The copy's tables are brought up to this code's version first, so a
        // snapshot never lands in a schema older than the one reading it.
        Artisan::call('migrate', ['--database' => $connection, '--force' => true]);

        $db = DB::connection($connection);
        $schema = Schema::connection($connection);
        $skip = config('mirror.skip_tables', []);

        $file = gzopen($path, 'rb');

        if ($file === false) {
            throw new RuntimeException('The snapshot file could not be opened.');
        }

        $done = ['tables' => 0, 'rows' => 0, 'made_at' => null];

        $db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $db->transaction(function () use ($db, $schema, $skip, $file, &$done) {
                $table = null;
                $columns = [];
                $keep = [];
                $batch = [];

                $flush = function () use (&$batch, &$table, $db) {
                    if ($batch !== [] && $table !== null) {
                        $db->table($table)->insert($batch);
                    }

                    $batch = [];
                };

                while (($line = gzgets($file)) !== false) {
                    $data = json_decode($line, true);

                    if (! is_array($data)) {
                        throw new RuntimeException('The snapshot is damaged: a line could not be read.');
                    }

                    if (isset($data['meta'])) {
                        if (($data['meta']['version'] ?? null) !== Snapshot::VERSION) {
                            throw new RuntimeException('This snapshot was made by a different version and cannot be read.');
                        }

                        $done['made_at'] = $data['meta']['made_at'] ?? null;

                        continue;
                    }

                    if (isset($data['table'])) {
                        $flush();

                        $table = (string) $data['table'];
                        $columns = $data['columns'] ?? [];

                        // A table this side does not have, or does not copy, is passed over.
                        if (in_array($table, $skip, true) || ! $schema->hasTable($table)) {
                            $table = null;

                            continue;
                        }

                        // Only the columns both sides know: the two can be a
                        // version apart for the length of an update.
                        $here = array_flip($schema->getColumnListing($table));
                        $keep = array_keys(array_filter($columns, fn ($column) => isset($here[$column])));
                        $columns = array_values(array_intersect_key($columns, array_flip($keep)));

                        $db->table($table)->delete();
                        $done['tables']++;

                        continue;
                    }

                    if ($table === null || ! isset($data['row'])) {
                        continue;
                    }

                    $batch[] = array_combine($columns, array_values(array_intersect_key($data['row'], array_flip($keep))));
                    $done['rows']++;

                    if (count($batch) >= 400) {
                        $flush();
                    }
                }

                $flush();

                if ($done['tables'] === 0) {
                    throw new RuntimeException('The snapshot held no tables.');
                }
            });
        } finally {
            gzclose($file);
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // Anything remembered about the old rows — permissions above all — is stale now.
        if ($schema->hasTable('cache')) {
            $db->table('cache')->delete();
        }

        return $done;
    }
}
