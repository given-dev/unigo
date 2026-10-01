<?php
/**
 * UniGo - Activity logging.
 *
 * Auditing trail for security relevant actions and any change to business
 * data. Written asynchronously in the sense that a logging failure can never
 * break the user facing request.
 */

declare(strict_types=1);

namespace App\Core;

final class ActivityLog
{
    /** Actions that are always recorded. */
    public const LOGIN = 'auth.login';
    public const LOGIN_FAILED = 'auth.login_failed';
    public const LOGOUT = 'auth.logout';
    public const REGISTER = 'auth.register';
    public const PASSWORD_CHANGED = 'auth.password_changed';
    public const ACCOUNT_SUSPENDED = 'account.suspended';
    public const ACCOUNT_ACTIVATED = 'account.activated';

    public const BOOKING_CREATED = 'booking.created';
    public const BOOKING_CANCELLED = 'booking.cancelled';
    public const PAYMENT_RECORDED = 'payment.recorded';
    public const TRIP_UPDATED = 'trip.updated';
    public const VEHICLE_UPDATED = 'vehicle.updated';
    public const ROUTE_UPDATED = 'route.updated';
    public const DELIVERY_UPDATED = 'delivery.updated';
    public const EMERGENCY_RAISED = 'emergency.raised';
    public const EMERGENCY_UPDATED = 'emergency.updated';
    public const COMPLAINT_CREATED = 'complaint.created';

    public static function record(
        string $action,
        ?int $entityId = null,
        string $entityType = '',
        string $description = '',
        ?int $userId = null
    ): void {
        try {
            $userId = $userId ?? (Auth::check() ? (int) Auth::id() : null);
            Database::instance()->insert('activity_logs', [
                'user_id'      => $userId,
                'action'       => substr($action, 0, 80),
                'entity_type'  => substr($entityType, 0, 40),
                'entity_id'    => $entityId,
                'description'  => substr($description, 0, 255),
                'ip_address'   => Request::isCli() ? 'cli' : Request::instance()->ip(),
                'user_agent'   => Request::isCli() ? 'cli' : Request::instance()->userAgent(),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Never let auditing break the request.
            ErrorHandler::log('warning', 'ActivityLog failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** Paginated feed for the admin dashboard. */
    public static function recent(int $limit = 15): array
    {
        return Database::instance()->select(
            'SELECT al.*, u.first_name, u.last_name
             FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id
             ORDER BY al.id DESC
             LIMIT ' . max(1, min(100, $limit))
        );
    }
}
