<?php

/*
 * Writes settings into the application's .env file, for the shop installers.
 *
 *   php set-env.php /path/to/app KEY=value KEY2="value with spaces" …
 *
 * Starts from .env.example the first time. A key that is already there is
 * replaced; a new one is added at the end. Used by both the Windows and the
 * Mac installer so the two cannot write the file differently.
 */

$app = rtrim($argv[1] ?? '', '/\\');

if ($app === '' || ! is_file($app.'/.env.example')) {
    fwrite(STDERR, "set-env: no application at '$app'\n");
    exit(1);
}

$file = $app.'/.env';
$text = is_file($file) ? file_get_contents($file) : file_get_contents($app.'/.env.example');

foreach (array_slice($argv, 2) as $pair) {
    [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

    if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
        fwrite(STDERR, "set-env: '$key' is not a setting name\n");
        exit(1);
    }

    // Quoted when it holds anything a .env file would misread.
    $written = $value === '' || preg_match('/^[A-Za-z0-9_.:\/@+-]+$/', $value)
        ? $value
        : '"'.addcslashes($value, "\\\"$").'"';

    $line = $key.'='.$written;
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

    $text = preg_match($pattern, $text)
        ? preg_replace($pattern, addcslashes($line, '\\$'), $text, 1)
        : rtrim($text, "\r\n").PHP_EOL.$line.PHP_EOL;
}

file_put_contents($file, $text);
