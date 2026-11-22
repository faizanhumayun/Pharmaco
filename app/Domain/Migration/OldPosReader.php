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

        // Already converted on another computer (oldpos:convert): the same
        // three lists, written out as a plain file that needs no tool to read.
        if ($this->isConverted($path)) {
            return $this->readConverted($path);
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

    /** The name the converted file carries, so no other JSON is mistaken for one. */
    public const CONVERTED_FORMAT = 'pharmaco-old-pos';

    /**
     * Writes the three lists out of an old POS database as one plain file.
     *
     * For a computer that cannot read .mdb files itself — a Windows shop
     * computer has no mdbtools. The file is converted where it can be read,
     * and the result is what is uploaded there instead. Only the three lists
     * go into it: nothing else in the old database ever leaves it.
     *
     * @return array{products: int, suppliers: int, customers: int}
     */
    public function convert(string $from, string $to): array
    {
        $data = $this->read($from);

        file_put_contents($to, json_encode(
            ['format' => self::CONVERTED_FORMAT, 'version' => 1, 'made_from' => basename($from)] + $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ));

        return array_map('count', $data);
    }

    private function isConverted(string $path): bool
    {
        $start = (string) file_get_contents($path, false, null, 0, 64);

        return str_starts_with(ltrim($start), '{');
    }

    /** @return array{products: list<array<string, string>>, suppliers: list<array<string, string>>, customers: list<array<string, string>>} */
    private function readConverted(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ($data['format'] ?? null) !== self::CONVERTED_FORMAT) {
            throw new OldPosException('This is not a converted old POS file. Upload the .mdb file, or the .json file that oldpos:convert made from it.');
        }

        $lists = [];

        foreach (['products', 'suppliers', 'customers'] as $list) {
            if (! is_array($data[$list] ?? null)) {
                throw new OldPosException('The converted file is incomplete: it has no '.$list.' list. Convert the .mdb file again.');
            }

            // Every value as text, exactly as reading the .mdb gives it.
            $lists[$list] = array_values(array_map(
                fn ($row) => array_map(fn ($value) => (string) ($value ?? ''), (array) $row),
                $data[$list]
            ));
        }

        return $lists;
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

        try {
            $process->run();
        } catch (\Throwable) {
            // The server will not start programs at all (proc_open switched off).
            throw new OldPosException(self::NOT_INSTALLED);
        }

        // 126 and 127 are the shell saying the program could not be run or
        // was not found — the tool is missing, not the file unreadable.
        // Windows says the same thing in words, with an exit code of 1.
        $notFound = in_array($process->getExitCode(), [126, 127], true)
            || (PHP_OS_FAMILY === 'Windows' && ! $process->isSuccessful()
                && (str_contains($process->getErrorOutput(), 'not recognized') || trim($process->getOutput().$process->getErrorOutput()) === ''));

        if ($notFound) {
            throw new OldPosException(self::NOT_INSTALLED);
        }

        if (! $process->isSuccessful()) {
            throw new OldPosException(
                'The file could not be read as an old POS database. If it is the newer encrypted kind (.accdb with a '
                .'password), open it in Access, remove the encryption and upload it again.'
            );
        }

        return $process->getOutput();
    }

    private const NOT_INSTALLED = 'This computer cannot read .mdb files itself: the tool for it (mdbtools) is not installed. '
        .'Either convert the file on a computer that has it — "php artisan oldpos:convert file.mdb" — and upload the .json file that makes, '
        .'or install mdbtools here ("brew install mdbtools" on a Mac, "apt install mdbtools" on Linux; there is none for Windows).';

    /**
     * mdbtools is not always on the web server's own path, so it is looked for.
     *
     * A hosting panel may forbid PHP from looking outside the site's own
     * folder (open_basedir). Looking is then refused with a warning, though
     * running the program is still allowed — so the check is silenced, and
     * when no folder could be confirmed the bare name is handed to the system
     * to find on its own path.
     */
    private function binary(string $tool): string
    {
        foreach (array_filter([config('services.mdbtools.path'), '/opt/homebrew/bin', '/usr/local/bin', '/usr/bin']) as $dir) {
            if (@is_executable($dir.'/'.$tool)) {
                return $dir.'/'.$tool;
            }
        }

        // An explicit folder is trusted even when it cannot be looked into.
        if ($dir = config('services.mdbtools.path')) {
            return rtrim($dir, '/').'/'.$tool;
        }

        return $tool;
    }
}
