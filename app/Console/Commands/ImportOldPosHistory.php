<?php

namespace App\Console\Commands;

use App\Domain\Migration\OldPosHistory;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * A new business made from an old POS database, with its history: every
 * delivery, bill, refund, credit collection and expense on its own date, and
 * each day closed. Supplier payments are not brought over.
 */
class ImportOldPosHistory extends Command
{
    protected $signature = 'oldpos:history
        {file : The old POS .mdb file}
        {--name= : Name of the new business}
        {--user=1 : Id of the user the records are made by}
        {--until= : Stop after this date (Y-m-d), for a trial run}
        {--no-close : Leave the days open instead of closing each one}';

    protected $description = 'Create a new business from an old POS database, replaying its history day by day';

    public function handle(OldPosHistory $history): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error('There is no file at '.$file);

            return self::FAILURE;
        }

        $user = User::find((int) $this->option('user'));

        if ($user === null) {
            $this->error('There is no user '.$this->option('user'));

            return self::FAILURE;
        }

        ini_set('memory_limit', '2G');
        $started = microtime(true);

        $done = $history->run(
            $file,
            (string) ($this->option('name') ?: 'Old POS history'),
            $user,
            close: ! $this->option('no-close'),
            until: $this->option('until') ?: null,
            say: fn (string $line) => $this->line($line),
        );

        $this->newLine();
        $this->table(['', ''], collect($done)->map(fn ($v, $k) => [str_replace('_', ' ', $k), is_numeric($v) && ! str_contains((string) $v, '.') ? number_format((int) $v) : $v])->values()->all());
        $this->info(sprintf('Done in %.1f minutes.', (microtime(true) - $started) / 60));

        return self::SUCCESS;
    }
}
