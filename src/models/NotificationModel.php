<?php
/**
 * UniGo - Notification model.
 *
 * Queries for the notifications table. Push/broadcast logic lives in
 * App\Services\NotificationService.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Paginator;
final class NotificationModel extends BaseModel
{
    protected string $table = 'notifications';

    /**
     * @param array{type?:string,is_read?:int} $filters
     * @return array{items:array<int,array<string,mixed>>,paginator:Paginator}
     */
    public function paginateForUser(int $userId, array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $where = ['n.user_id = ?'];
        $params = [$userId];

        if (isset($filters['is_read']) && $filters['is_read'] !== '') {
            $where[] = 'n.is_read = ?';
            $params[] = (int) $filters['is_read'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'n.type = ?';
            $params[] = $filters['type'];
        }

        return $this->paginateQuery([
            'select' => 'n.*',
            'from'   => 'notifications n',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 'n.created_at DESC',
        ], $page, $perPage);
    }

    public function unreadCount(int $userId): int
    {
        return $this->db->count(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    /** Latest N unread notifications - used by the bell dropdown + toast feed. */
    public function latest(int $userId, int $limit = 8): array
    {
        $limit = max(1, min(30, $limit));
        return $this->db->select(
            "SELECT * FROM notifications
             WHERE user_id = ? ORDER BY is_read ASC, created_at DESC
             LIMIT $limit",
            [$userId]
        );
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        return $this->db->update('notifications', [
            'is_read' => 1,
            'read_at' => date('Y-m-d H:i:s'),
        ], 'id = ? AND user_id = ? AND is_read = 0', [$notificationId, $userId]) > 0;
    }

    public function markAllRead(int $userId): int
    {
        return $this->db->update('notifications', [
            'is_read' => 1,
            'read_at' => date('Y-m-d H:i:s'),
        ], 'user_id = ? AND is_read = 0', [$userId]);
    }

    public function delete(int $notificationId, int $userId): bool
    {
        return $this->db->delete('notifications', 'id = ? AND user_id = ?', [$notificationId, $userId]) > 0;
    }
}