<?php
/**
 * UniGo - Notification service.
 *
 * Delivery today is "insert a row, let the client poll". The push() signature
 * is intentionally identical to what a WebSocket/SSE publisher needs, so
 * moving to a queue + socket server later only requires a new transport.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
final class NotificationService
{
    /** Create one notification. */
    public static function push(
        int $userId,
        string $type,
        string $title,
        string $message = '',
        ?string $link = null,
        string $severity = 'info',
        string $icon = 'bell'
    ): ?int {
        if ($userId <= 0) {
            return null;
        }
        try {
            return Database::instance()->insert('notifications', [
                'user_id'   => $userId,
                'type'      => $type,
                'title'     => mb_substr($title, 0, 120),
                'message'   => mb_substr($message, 0, 255),
                'link'      => $link,
                'icon'      => $icon,
                'severity'  => $severity,
                'is_read'   => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::log('warning', 'Notification failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** Fan-out to many users (used for system announcements). */
    public static function broadcast(array $userIds, string $type, string $title, string $message = '', ?string $link = null, string $severity = 'info'): int
    {
        $db = Database::instance();
        $rows = [];
        $now = date('Y-m-d H:i:s');
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            $rows[] = [$userId, $type, mb_substr($title, 0, 120), mb_substr($message, 0, 255), $link, 'megaphone', $severity, 0, $now];
        }
        if (!$rows) {
            return 0;
        }
        $db->run(
            'INSERT INTO notifications (user_id, type, title, message, link, icon, severity, is_read, created_at)
             VALUES ' . implode(',', array_fill(0, count($rows), '(?,?,?,?,?,?,?,?,?)')),
            array_merge(...$rows)
        );
        return count($rows);
    }

    // ------------------------------------------------------------------
    // Domain events
    // ------------------------------------------------------------------

    public static function bookingConfirmed(int $userId, string $reference, string $tripCode, string $routeName): void
    {
        self::push(
            $userId,
            'booking',
            'Booking confirmed: ' . $reference,
            'Your seat on ' . $tripCode . ' (' . $routeName . ') is confirmed.',
            '/passenger/bookings',
            'success',
            'ticket'
        );
    }

    public static function bookingCancelled(int $userId, string $reference, string $tripCode): void
    {
        self::push(
            $userId,
            'booking',
            'Booking cancelled: ' . $reference,
            'Booking ' . $reference . ' on trip ' . $tripCode . ' has been cancelled.',
            '/passenger/bookings',
            'warning',
            'x'
        );
    }

    public static function tripAssigned(int $driverId, int $tripId, string $routeName, string $departure): void
    {
        self::push(
            $driverId,
            'trip',
            'New trip assigned',
            $routeName . ' departs at ' . date('H:i', strtotime($departure)) . '.',
            '/driver/trips/' . $tripId,
            'info',
            'steering'
        );
    }

    public static function tripBoarding(int $tripId, string $tripCode, string $routeName, string $departure): void
    {
        $passengers = Database::instance()->select(
            'SELECT passenger_id FROM bookings WHERE trip_id = ? AND status IN ("pending","confirmed")',
            [$tripId]
        );
        foreach ($passengers as $p) {
            self::push(
                (int) $p['passenger_id'],
                'trip',
                'Boarding now: ' . $tripCode,
                $routeName . ' is boarding. Please be at the pickup point before ' . date('H:i', strtotime($departure)) . '.',
                '/passenger/trips/' . $tripId,
                'info',
                'bus'
            );
        }
    }

    public static function tripCancelled(int $userId, string $tripCode, string $routeName): void
    {
        self::push(
            $userId,
            'trip',
            'Trip cancelled: ' . $tripCode,
            $routeName . ' has been cancelled. Any payment will be refunded.',
            '/passenger/bookings',
            'danger',
            'alert'
        );
    }

    public static function paymentSuccessful(int $userId, string $reference, float $amount): void
    {
        self::push(
            $userId,
            'payment',
            'Payment received',
            'Payment ' . $reference . ' for ' . number_format($amount, 0) . ' was successful.',
            '/passenger/payments',
            'success',
            'wallet'
        );
    }

    public static function vehicleApproaching(int $userId, int $tripId, string $tripCode, int $minutes): void
    {
        self::push(
            $userId,
            'trip',
            'Your vehicle is approaching',
            'Trip ' . $tripCode . ' is about ' . $minutes . ' minutes away.',
            '/passenger/trips/' . $tripId,
            'info',
            'map'
        );
    }

    public static function deliveryStatus(int $userId, string $tracking, string $status): void
    {
        self::push(
            $userId,
            'delivery',
            'Parcel update: ' . strtoupper(str_replace('_', ' ', $status)),
            'Your parcel ' . $tracking . ' is now ' . str_replace('_', ' ', $status) . '.',
            '/passenger/deliveries',
            $status === 'delivered' ? 'success' : 'info',
            'box'
        );
    }

    public static function emergencyBroadcast(int $vehicleDriverId, string $reference, string $type): void
    {
        self::push(
            $vehicleDriverId,
            'emergency',
            'Emergency alert nearby: ' . strtoupper(str_replace('_', ' ', $type)),
            'Emergency ' . $reference . ' has been raised near your vehicle. Check the emergency screen.',
            '/driver/emergency',
            'danger',
            'sos'
        );
    }
}