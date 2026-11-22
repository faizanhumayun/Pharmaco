<?php

namespace App\Console\Commands;

use App\Domain\Mirror\SnapshotImporter;
use Illuminate\Console\Command;

/**
 * Puts the books back from a snapshot file — onto a replacement computer, or
 * onto a new one that is taking over from the platform.
 *
 * It replaces everything in this installation's database, so it asks first.
 */
class MirrorRestore extends Command
{
    protected $signature = 'mirror:restore {file : A snapshot file made by mirror:push} {--force : Do not ask}';

    protected $description = 'Replace this installation\'s books with those in a snapshot file';

    public function handle(SnapshotImporter $importer): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error('There is no file at '.$file);

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This replaces EVERYTHING in this installation\'s database with the snapshot. Continue?')) {
            return self::SUCCESS;
        }

        $done = $importer->import($file, (string) config('database.default'));

        $this->info(sprintf('Restored %s rows into %d tables. Snapshot made %s.', number_format($done['rows']), $done['tables'], $done['made_at'] ?? 'at an unknown time'));

        return self::SUCCESS;
    }
}
