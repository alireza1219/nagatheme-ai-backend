<?php

namespace App\Helpers;

/**
 * Environment configuration loader.
 *
 * Loads variables from .env file and provides typed access methods.
 */
class Env
{
    /** @var bool Whether the .env file has been loaded. */
    private static bool $loaded = false;

    /**
     * Load environment variables from a .env file.
     *
     * @param string $path Full path to the .env file.
     *
     * @return void
     * @throws \RuntimeException If file is not found or not readable.
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return; // Don't load twice
        }

        if (!file_exists($path)) {
            throw new \RuntimeException(".env file not found at: {$path}");
        }

        if (!is_readable($path)) {
            throw new \RuntimeException(".env file is not readable: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Must contain =
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip surrounding quotes ("value" or 'value')
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                $value = $matches[2];
            }

            // Don't overwrite existing real environment variables
            if (!isset($_ENV[$key])) {
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    /**
     * Get a string environment variable.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? $default;
    }

    /**
     * Get an integer environment variable.
     */
    public static function getInt(string $key, ?int $default = null): ?int
    {
        $value = self::get($key);
        return $value !== null ? (int) $value : $default;
    }

    /**
     * Get a float environment variable.
     */
    public static function getFloat(string $key, ?float $default = null): ?float
    {
        $value = self::get($key);
        return $value !== null ? (float) $value : $default;
    }

    /**
     * Get a boolean environment variable.
     * Recognizes: true/1/yes/on → true, everything else → false
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Require that specific environment variables exist and are non-empty.
     *
     * @param array $keys List of required variable names.
     * @throws \RuntimeException If any are missing.
     */
    public static function require(array $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
                $missing[] = $key;
            }
        }
        if (!empty($missing)) {
            throw new \RuntimeException(
                'Missing required .env variables: ' . implode(', ', $missing)
            );
        }
    }
}
