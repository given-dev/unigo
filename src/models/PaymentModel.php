<?php
/**
 * UniGo - Payment model (ledger queries).
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Paginator;

final class PaymentModel extends BaseModel
{
    protected string $table = 'payments';

    public function findDetailed(int $id): ?array
    {
        return $this->db->first(
            'SELECT p.*, u.first_name, u.last_name, u.email,
                    b.reference AS booking_reference, t.trip_code, r.name AS route_name,
                    d.tracking_number
             FROM payments p
             LEFT JOIN users u ON u.id = p.user_id
             LEFT JOIN bookings b ON b.id = p.booking_id
             LEFT JOIN trips t ON t.id = b.trip_id
             LEFT JOIN routes r ON r.id = t.route_id
             LEFT JOIN deliveries d ON d.id = p.delivery_id
             WHERE p.id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * @param array{user_id?:int,status?:string,method?:string,date?:string,from_date?:string,search?:string} $filters
     * @return array{items:array<int,array<string,mixed>>,paginator:Paginator}
     */
    public function paginatePayments(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'p.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['method'])) {
            $where[] = 'p.method = ?';
            $params[] = $filters['method'];
        }
        if (!empty($filters['date'])) {
            $where[] = 'DATE(p.created_at) = ?';
            $params[] = $filters['date'];
        }
        if (!empty($filters['from_date'])) {
            $where[] = 'p.created_at >= ?';
            $params[] = $filters['from_date'] . ' 00:00:00';
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.reference LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR b.reference LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        return $this->paginateQuery([
            'select' => 'p.*, u.first_name, u.last_name, u.email, b.reference AS booking_reference',
            'from'   => 'payments p',
            'joins'  => 'LEFT JOIN users u ON u.id = p.user_id LEFT JOIN bookings b ON b.id = p.booking_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 'p.created_at DESC',
        ], $page, $perPage);
    }

    /** Aggregated revenue for dashboards - one query, never per-row. */
    public function revenueSummary(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        return $this->db->first(
            "SELECT COALESCE(SUM(CASE WHEN status = 'successful' THEN amount ELSE 0 END),0) AS gross,
                    COALESCE(SUM(CASE WHEN status = 'refunded'  THEN amount ELSE 0 END),0) AS refunded,
                    COUNT(*) AS transactions,
                    COUNT(DISTINCT user_id) AS paying_users
             FROM payments
             WHERE created_at >= (CURDATE() - INTERVAL ? DAY)",
            [$days]
        ) ?? [];
    }

    public function dailySeries(int $days = 14): array
    {
        return $this->dailySeriesQuery('payments', 'created_at', [
            'revenue'      => "SUM(CASE WHEN status = 'successful' THEN amount ELSE 0 END)",
            'transactions' => 'COUNT(*)',
        ], $days);
    }

    public function byMethod(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        return $this->db->select(
            "SELECT method AS label, COUNT(*) AS total, COALESCE(SUM(amount),0) AS amount
             FROM payments
             WHERE created_at >= (CURDATE() - INTERVAL ? DAY)
             GROUP BY method ORDER BY total DESC",
            [$days]
        );
    }

    public function revenueToday(): float
    {
        return (float) $this->db->value(
            "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'successful' AND DATE(created_at) = CURDATE()"
        );
    }
}
