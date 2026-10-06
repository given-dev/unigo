<?php
/**
 * UniGo - Settings service.
 *
 * Runtime configuration lives in the system_settings table so an operator can
 * change things (support numbers, demo mode, cancellation window) without
 * touching code. Values are cached for the lifetime of the request; swap the
 * in-memory array for Redis/Memcached when running multiple app servers.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use Throwable;

final class SettingsService
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** Load settings once and push known keys into Config overrides. */
    public static function bootstrap(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        $rows = Database::instance()->select('SELECT setting_key, setting_value FROM system_settings');
        foreach ($rows as $row) {
            self::$cache[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
        self::applyOverrides();
    }

    public static function get(string $key, $default = null)
    {
        if (self::$cache === null) {
            try {
                self::bootstrap();
            } catch (Throwable $e) {
                return $default;
            }
        }
        $value = self::$cache[$key] ?? null;
        return $value === null || $value === '' ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }

    public static function set(string $key, string $value): void
    {
        $db = Database::instance();
        $exists = $db->exists('SELECT 1 FROM system_settings WHERE setting_key = ?', [$key]);
        if ($exists) {
            $db->update('system_settings', ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s')], 'setting_key = ?', [$key]);
        } else {
            $db->insert('system_settings', [
                'setting_key'   => $key,
                'setting_value' => $value,
                'setting_type'  => 'string',
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        }
        self::$cache[$key] = $value;
        self::applyOverrides();
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::bootstrap();
        }
        return self::$cache ?? [];
    }

    /** Map whitelisted keys onto Config so views/services read one source. */
    private static function applyOverrides(): void
    {
        $map = [
            'support_phone'     => 'domain.support_phone',
            'support_email'     => 'domain.support_email',
            'emergency_hotline' => 'domain.emergency_hotline',
            'cancellation_window_minutes' => 'domain.cancellation_window_min',
        ];
        foreach ($map as $settingKey => $configKey) {
            if (isset(self::$cache[$settingKey]) && self::$cache[$settingKey] !== '') {
                $value = self::$cache[$settingKey];
                if ($settingKey === 'cancellation_window_minutes') {
                    $value = (int) $value;
                }
                Config::setOverride($configKey, $value);
            }
        }
    }
}
