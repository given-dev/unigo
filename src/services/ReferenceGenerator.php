<?php
/**
 * UniGo - Reference generator.
 *
 * Human friendly, sortable, collision checked document numbers:
 *   booking   UG-2026-000001
 *   delivery  UNI-GO-000001
 *   trip      TRP-2026-000001
 *
 * The generator reads the per-year counter from the table itself (MAX + 1)
 * inside the caller's transaction and relies on the UNIQUE index to reject
 * the astronomically unlikely race.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

final class ReferenceGenerator
{
    /**
     * @param string $kind  booking | delivery | trip | payment | complaint | emergency
     */
    public static function generate(string $kind, bool $withYear = true): string
    {
        $prefix = self::prefix($kind);
        $table  = self::tableFor($kind);
        $column = self::columnFor($kind);

        if ($table === null) {
            return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
        }

        $year = date('Y');
        // $like already carries its trailing separator (UG-2026- or UNI-GO-).
        $like = $withYear ? $prefix . '-' . $year . '-' : $prefix . '-';

        // Only pure numeric tails count towards the sequence. Sorting or casting
        // blindly would let a stray row (e.g. an older PAY-ZVVPUEEXCY) reset the
        // counter, handing out the same reference twice.
        $tailStart = strlen($like) + 1;
        $sequence = (int) Database::instance()->value(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(`$column`, ?) AS UNSIGNED)), 0) AS seq
             FROM `$table`
             WHERE `$column` LIKE ?
               AND SUBSTRING(`$column`, ?) REGEXP '^[0-9]+$'",
            [$tailStart, $like . '%', $tailStart]
        );

        return sprintf('%s%06d', $like, $sequence + 1);
    }

    public static function prefix(string $kind): string
    {
        $map = (array) Config::get('app.reference_prefix', []);
        $settingKey = $kind === 'booking' ? 'booking_reference_prefix'
            : ($kind === 'delivery' ? 'delivery_reference_prefix' : null);

        if ($settingKey !== null) {
            $fromDb = SettingsService::get($settingKey);
            if ($fromDb) {
                return (string) $fromDb;
            }
        }
        return (string) ($map[$kind] ?? strtoupper(substr($kind, 0, 3)));
    }

    private static function tableFor(string $kind): ?string
    {
        $map = [
            'booking'   => 'bookings',
            'delivery'  => 'deliveries',
            'trip'      => 'trips',
            'payment'   => 'payments',
            'complaint' => 'complaints',
            'emergency' => 'emergency_alerts',
        ];
        return $map[$kind] ?? null;
    }

    private static function columnFor(string $kind): string
    {
        // Deliveries identify themselves by tracking number, not "reference".
        if ($kind === 'delivery') {
            return 'tracking_number';
        }

        return $kind === 'trip' ? 'trip_code' : 'reference';
    }
}
