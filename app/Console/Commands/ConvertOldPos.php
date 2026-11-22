<?php

namespace App\Console\Commands;

use App\Domain\Migration\OldPosException;
use App\Domain\Migration\OldPosReader;
use Illuminate\Console\Command;

/**
 * Turns an old POS database (.mdb) into a plain file that any installation
 * can import — for a shop computer that cannot read .mdb files itself.
 *
 * Run where mdbtools is installed (the platform, or a developer's machine),
 * then upload the .json it writes on the shop computer's import page exactly
 * as the .mdb would have been. Only the product, supplier and customer lists
 * go into it; the old system's logins and settings stay behind.
 */
class ConvertOldPos extends Command
{
    protected $signature = 'oldpos:convert {file : The old POS .mdb file} {--out= : Where to write the converted file}';

    protected $description = 'Convert an old POS .mdb file into a .json file a shop computer can import';

    public function handle(OldPosReader $reader): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error('There is no file at '.$file);

            return self::FAILURE;
        }

        $out = (string) ($this->option('out') ?: preg_replace('/\.(mdb|accdb)$/i', '', $file).'.pharmaco.json');

        try {
            $counts = $reader->convert($file, $out);
        } catch (OldPosException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Converted: %s products, %s suppliers, %s customers.',
            number_format($counts['products']), number_format($counts['suppliers']), number_format($counts['customers'])
        ));
        $this->line('Upload this file on the shop computer\'s import page: '.$out);

        return self::SUCCESS;
    }
}
