<?php

declare(strict_types=1);

namespace GuicheExample\ClinicaPiloto\Support;

final class EnvLoader
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $position = strpos($line, '=');

            if ($position === false) {
                continue;
            }

            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            if ($key === '' || preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key) !== 1) {
                continue;
            }

            if (getenv($key) !== false) {
                continue;
            }

            if (
                strlen($value) >= 2
                && (($value[0] === '"' && $value[-1] === '"')
                    || ($value[0] === "'" && $value[-1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
