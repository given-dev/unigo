<?php
/**
 * UniGo - signed-in dashboards.
 *
 * The passenger home is fully populated. Staff roles get a shared overview
 * scaffold backed by live headline statistics; their deep workspaces are
 * added route by route.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\ErrorHandler;
use App\Models\BookingModel;
use App\Models\NotificationModel;
use App\Models\StatsModel;
use App\Models\TripModel;

final class DashboardController extends Controller
{
    /** /dashboard - send everyone to the dashboard for their role. */
    public function home(): void
    {
        $this->requireLogin();
        $this->redirect(Auth::homeRoute());
    }

    /** /home - passenger dashboard. */
    public function index(): void
    {
        $this->requireLogin();

        if (!Auth::isPassenger()) {
            $this->redirect(Auth::homeRoute());
        }

        $uid      = (int) Auth::id();
        $upcoming = $this->safe(static fn () => (new TripModel())->forPassenger($uid, 5), []);
        $bookings = $this->safe(
            static fn () => (new BookingModel())->paginateBookings(['passenger_id' => $uid], 1, 5),
            ['items' => [], 'paginator' => null]
        );
        $notif    = $this->notifications($uid);

        $this->view('dashboard/passenger', [
            'title'                 => 'Home - ' . app_name(),
            'pageTitle'             => 'Home',
            'pageSub'               => 'Your journeys at a glance',
            'upcoming'              => $upcoming,
            'bookings'              => $bookings['items'] ?? [],
            'bookingsTotal'         => $bookings['paginator']->total ?? count($bookings['items'] ?? []),
            'summary'               => $this->passengerSummary($uid),
            'navBadges'             => ['/bookings' => count($upcoming)],
            'unreadNotifications'   => $notif['unread'],
            'recentNotifications'   => $notif['recent'],
        ], 'layouts/app');
    }

    public function driver(): void
    {
        $this->roleDashboard('driver');
    }

    public function operator(): void
    {
        $this->roleDashboard('operator');
    }

    public function authority(): void
    {
        $this->roleDashboard('authority');
    }

    public function admin(): void
    {
        $this->roleDashboard('admin');
    }

    private function roleDashboard(string $role): void
    {
        $this->requireRole($role);

        $uid     = (int) Auth::id();
        $notif   = $this->notifications($uid);

        $this->view('dashboard/overview', [
            'title'               => ucfirst($role) . ' dashboard - ' . app_name(),
            'pageTitle'           => ucfirst($role) . ' dashboard',
            'pageSub'             => 'Platform overview',
            'role'                => $role,
            'headline'            => $this->safe(static fn () => (new StatsModel())->headline(), []),
            'unreadNotifications' => $notif['unread'],
            'recentNotifications' => $notif['recent'],
        ], 'layouts/app');
    }

    /** @return array{unread:int,recent:array} */
    private function notifications(int $userId): array
    {
        return [
            'unread' => $this->safe(static fn () => (new NotificationModel())->unreadCount($userId), 0),
            'recent' => $this->safe(static fn () => (new NotificationModel())->latest($userId, 5), []),
        ];
    }

    /** @return array{total_bookings:int,total_spent:float,loyalty_points:int} */
    private function passengerSummary(int $userId): array
    {
        return $this->safe(static function () use ($userId): array {
            $row = Database::instance()->first(
                'SELECT total_bookings, total_spent, loyalty_points FROM passengers WHERE user_id = ? LIMIT 1',
                [$userId]
            );
            return [
                'total_bookings' => (int) ($row['total_bookings'] ?? 0),
                'total_spent'    => (float) ($row['total_spent'] ?? 0),
                'loyalty_points' => (int) ($row['loyalty_points'] ?? 0),
            ];
        }, ['total_bookings' => 0, 'total_spent' => 0.0, 'loyalty_points' => 0]);
    }

    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Dashboard data unavailable: ' . $e->getMessage());
            return $fallback;
        }
    }
}
