<?php
/**
 * UniGo - notifications inbox.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Models\NotificationModel;

final class NotificationController extends Controller
{
    /** /notifications - the signed-in user's inbox. */
    public function unreadCount(): void
    {
        $this->requireLogin();
        \App\Core\Response::success(['unread' => (new \App\Models\NotificationModel())->unreadCount((int) \App\Core\Auth::id())])->withHeader('Cache-Control', 'no-store')->send();
    }

    public function index(): void
    {
        $this->requireLogin();

        $page   = max(1, $this->request->int('page', 1));
        $unread = $this->request->str('filter') === 'unread' ? 0 : null;

        $filters = [];
        if ($unread !== null) {
            $filters['is_read'] = $unread;
        }

        $result = $this->safe(
            static fn () => (new NotificationModel())->paginateForUser((int) Auth::id(), $filters, $page, 15),
            ['items' => [], 'paginator' => null]
        );

        $this->view('notifications/index', [
            'title'     => 'Notifications - ' . app_name(),
            'pageTitle' => 'Notifications',
            'pageSub'   => 'Updates about your bookings, parcels and alerts',
            'items'     => $result['items'] ?? [],
            'paginator' => $result['paginator'] ?? null,
            'filter'    => $this->request->str('filter'),
            'unread'    => $this->safe(static fn () => (new NotificationModel())->unreadCount((int) Auth::id()), 0),
        ], 'layouts/app');
    }

    /** POST /notifications/read-all. */
    public function readAll(): void
    {
        $this->requireLogin();
        $this->verifyCsrf();

        try {
            $count = (new NotificationModel())->markAllRead((int) Auth::id());
            Flash::success($count > 0 ? 'Marked ' . $count . ' notification(s) as read.' : 'Nothing to mark.');
        } catch (\Throwable $e) {
            ErrorHandler::log('error', 'Mark-all-read failed: ' . $e->getMessage());
            Flash::error('We could not update your notifications.');
        }

        $this->redirect('/notifications');
    }

    /** POST /notifications/{id}/read. */
    public function read(string $id): void
    {
        $this->requireLogin();
        $this->verifyCsrf();

        try {
            (new NotificationModel())->markRead((int) $id, (int) Auth::id());
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Mark-read failed: ' . $e->getMessage());
        }

        $this->redirect('/notifications');
    }

    private function safe(callable $callback, $fallback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            ErrorHandler::log('warning', 'Notification data unavailable: ' . $e->getMessage());
            return $fallback;
        }
    }
}
