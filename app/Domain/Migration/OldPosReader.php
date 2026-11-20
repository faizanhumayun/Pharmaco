<?php

namespace App\Domain\Migration;

use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

/**
 * Reads the three lists worth moving out of an old POS database (.mdb).
 *
 * The file is a Microsoft Access database, read with mdbtools. It holds far
 * more than is wanted — including the old system's own logins and connection
 * settings — so the tables read are named here and nothing else is ever
 * opened: products, suppliers and customers.
 *
 * A database password set in Access does not stop mdbtools: for this file
 * format the password guards the Access program, not the data. A file that is
 * genuinely encrypted (the newer .accdb kind) cannot be read, and says so.
 */
class OldPosReader
{
    /** The only tables this ever opens. */
    private const TABLES = ['Product', 'Supplier', 'Customer_data'];

    /**
     * @return array{products: list<array<string, string>>, suppliers: list<array<string, string>>, customers: list<array<string, string>>}
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new OldPosException('The uploaded file is no longer there. Upload it again.');
        }

        $present = $this->tables($path);
        $missing = array_diff(self::TABLES, $present);

        if ($missing !== []) {
            throw new OldPosException(
                'This does not look like the old POS database: it has no '.implode(', ', $missing).' table. '
                .'Only that POS system\'s own database can be imported.'
            );
        }

        return [
            'products' => $this->table($path, 'Product'),
            'suppliers' => $this->table($path, 'Supplier'),
            'customers' => $this->table($path, 'Customer_data'),
        ];
    }

    /** A date as the old system wrote it — 07/30/26 00:00:00 — or null. */
    public static function date(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (['m/d/y H:i:s', 'm/d/Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if ($date !== false) {
                    return $date->startOfDay();
                }
            } catch (\Throwable) {
                // Try the next way it might have been written.
            }
        }

        return null;
    }

    /** @return list<string> */
    private function tables(string $path): array
    {
        $out = $this->run('mdb-tables', ['-1', $path]);

        return array_values(array_filter(array_map('trim', explode("\n", $out))));
    }

    /** @return list<array<string, string>> */
    private function table(string $path, string $table): array
    {
        // Checked against the list again here, so no other caller can ever
        // widen what is read.
        if (! in_array($table, self::TABLES, true)) {
            throw new OldPosException('That table is not one this import reads.');
        }

        $csv = $this->run('mdb-export', [$path, $table]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream, null, ',', '"', '');
        $rows = [];

        if ($header === false || $header === null) {
            fclose($stream);

            return [];
        }

        while (($line = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }

            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }

        fclose($stream);

        return $rows;
    }

    /** @param list<string> $arguments */
    private function run(string $tool, array $arguments): string
    {
        $process = new Process([$this->binary($tool), ...$arguments]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new OldPosException(
                'The file could not be read as an old POS database. If it is the newer encrypted kind (.accdb with a '
                .'password), open it in Access, remove the encryption and upload it again.'
            );
        }

        return $process->getOutput();
    }

    /** mdbtools is not always on the web server's own path, so it is looked for. */
    private function binary(string $tool): string
    {
        foreach (array_filter([config('services.mdbtools.path'), '/opt/homebrew/bin', '/usr/local/bin', '/usr/bin']) as $dir) {
            if (is_executable($dir.'/'.$tool)) {
                return $dir.'/'.$tool;
            }
        }

        throw new OldPosException(
            'The tool that reads old POS databases (mdbtools) is not installed on this server. '
            .'Install it — "brew install mdbtools" on a Mac, "apt install mdbtools" on Linux — and try again.'
        );
    }
}
