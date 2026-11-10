<?php

namespace App\Support;

/**
 * Writes settings into a .env file, leaving every other line as it was.
 */
class EnvFile
{
    /** @param array<string, string> $values */
    public static function set(string $path, array $values): void
    {
        $contents = file_get_contents($path);

        foreach ($values as $key => $value) {
            $line = $key.'='.self::quote($value);
            $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';

            $contents = preg_match($pattern, $contents)
                ? preg_replace_callback($pattern, fn () => $line, $contents, 1)
                : rtrim($contents)."\n".$line."\n";
        }

        file_put_contents($path, $contents);
    }

    /** A password can hold anything, and an unquoted # or $ would change its meaning. */
    private static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[\w.:\/@+=,-]+$/', $value)) {
            return $value;
        }

        return str_contains($value, "'")
            ? '"'.addcslashes($value, '\\"$').'"'
            : "'".$value."'";
    }
}
