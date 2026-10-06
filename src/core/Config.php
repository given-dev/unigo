<?php
/**
 * UniGo — configuration loader.
 *
 * Reads config/config.php once and exposes it through Config::get().
 * Values may be overridden at runtime by the system_settings table when
 * a SettingsService is available.
 */

declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @var array<string,mixed>|null */
    private static ?array $items = null;

    /** @var array<string,mixed> runtime overrides (system_settings) */
    private static array $overrides = [];

    public static function load(string $file): void
    {
        $envFile = dirname($file, 2) . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
                if (!preg_match('/^\s*(UNIGO_[A-Z0-9_]+)\s*=(.*)$/', $line, $match)) continue;
                if (getenv($match[1]) !== false) continue;
                $value = trim($match[2]);
                if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $decoded = $value[0] === '"' ? json_decode($value, true) : null;
                    $value = is_string($decoded) ? $decoded : substr($value, 1, -1);
                }
                putenv($match[1] . '=' . $value);
            }
        }
        self::$items = require $file;
    }

    /**
     * Dot notation accessor: Config::get('app.name')
     */
    public static function get(string $key, $default = null)
    {
        if (self::$items === null) {
            throw new \RuntimeException('Configuration has not been loaded.');
        }

        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }

        $value = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function setOverride(string $key, $value): void
    {
        self::$overrides[$key] = $value;
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return self::$items ?? [];
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('app.debug', false);
    }

    public static function env(): string
    {
        return (string) self::get('app.env', 'local');
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }
}
