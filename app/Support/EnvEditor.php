<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Reads and updates keys in the application's .env file.
 */
class EnvEditor
{
    public static function path(): string
    {
        return app()->environmentFilePath();
    }

    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    public static function set(array $values): void
    {
        $path = self::path();
        $content = file_exists($path) ? file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z0-9_]+$/', $key)) {
                throw new RuntimeException("Invalid env key: {$key}");
            }

            $line = $key.'='.self::format($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $content)) {
                $content = preg_replace_callback($pattern, fn () => $line, $content);
            } else {
                $content = rtrim($content, "\n")."\n".$line."\n";
            }
        }

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write {$path}");
        }

        if (app()->configurationIsCached()) {
            Artisan::call('config:clear');
        }
    }

    private static function format(string|int|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/@+-]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$', "\n"], ['\\\\', '\\"', '\\$', ''], $value).'"';
    }
}
