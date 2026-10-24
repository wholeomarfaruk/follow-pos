<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Writes one KEY=value line of the app's .env (replacing it, or appending
 * it), for settings the app must boot with — e.g. Site Settings › Timezone
 * → APP_TIMEZONE. A cached config is rebuilt so the new value takes effect.
 */
class EnvFile
{
    public static function set(string $key, string $value): void
    {
        $path = app()->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            throw new RuntimeException('.env file is missing or not writable.');
        }

        $contents = (string) file_get_contents($path);
        $line = $key . '=' . (preg_match('/[\s#"\'=$]/', $value) ? '"' . addcslashes($value, '"\\') . '"' : $value);
        // [^\r\n]* rather than .* so a CRLF file keeps its line endings.
        $pattern = '/^' . preg_quote($key, '/') . '=[^\r\n]*/m';

        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        $contents = preg_match($pattern, $contents)
            ? preg_replace_callback($pattern, fn () => $line, $contents, 1)
            : rtrim($contents, "\r\n") . $eol . $line . $eol;

        file_put_contents($path, $contents, LOCK_EX);

        if (app()->configurationIsCached()) {
            Artisan::call('config:cache');
        }
    }
}
