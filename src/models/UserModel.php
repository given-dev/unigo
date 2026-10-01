<?php
/**
 * UniGo - User & role model.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Paginator;
use App\Services\NotificationService;
use App\Services\SettingsService;

final class UserModel extends BaseModel
{
    protected string $table = 'users';

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function findWithRoles(int $id): ?array
    {
        return $this->db->first(
            'SELECT u.*, GROUP_CONCAT(r.slug ORDER BY r.slug SEPARATOR ",") AS role_slugs
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = ?
             GROUP BY u.id',
            [$id]
        );
    }

    public function findByEmail(string $email): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE email = ? LIMIT 1', [strtolower(trim($email))]);
    }

    /** @return array<int,array<string,mixed>> */
    public function roles(int $userId): array
    {
        return $this->db->select(
            'SELECT r.* FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? ORDER BY r.name',
            [$userId]
        );
    }

    /** @return array<int,string> */
    public function roleSlugs(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT r.slug FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?',
            [$userId]
        );
        return array_column($rows, 'slug');
    }

    public function roleId(string $slug): ?int
    {
        $v = $this->db->value('SELECT id FROM roles WHERE slug = ? LIMIT 1', [$slug]);
        return $v === null ? null : (int) $v;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Create a user together with its role(s) and profile row in one
     * transaction so a half-created account can never exist.
     */
    public function createUser(array $data, array $roles, array $profile = [], array $profileTable = []): int
    {
        return $this->db->transaction(function () use ($data, $roles, $profile, $profileTable): int {
            $userId = $this->create(array_merge([
                'email'         => strtolower(trim((string) $data['email'])),
                'password_hash' => $data['password_hash'],
                'first_name'    => $data['first_name'],
                'last_name'     => $data['last_name'] ?? '',
                'phone'         => $data['phone'] ?? '',
                'national_id'   => $data['national_id'] ?? '',
                'status'        => $data['status'] ?? 'active',
                'email_verified_at' => $data['status'] ?? 'active' === 'active' ? date('Y-m-d H:i:s') : null,
                'must_change_password' => (int) ($data['must_change_password'] ?? 0),
            ], isset($data['last_login_at']) ? [] : []));

            foreach ($roles as $slug) {
                $roleId = $this->roleId($slug);
                if ($roleId) {
                    $this->db->insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
                }
            }

            if ($profileTable && $profile) {
                $this->db->insert($profileTable, array_merge(['user_id' => $userId], $profile));
            }

            return $userId;
        });
    }

    public function assignRole(int $userId, string $slug): void
    {
        $roleId = $this->roleId($slug);
        if (!$roleId) {
            return;
        }
        if (!$this->db->exists('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?', [$userId, $roleId])) {
            $this->db->insert('user_roles', ['user_id' => $userId, 'role_id' => $roleId]);
        }
    }

    public function removeRole(int $userId, string $slug): void
    {
        $roleId = $this->roleId($slug);
        if ($roleId) {
            $this->db->delete('user_roles', 'user_id = ? AND role_id = ?', [$userId, $roleId]);
        }
    }

    public function setStatus(int $userId, string $status, ?int $actorId = null): void
    {
        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            return;
        }
        $this->updateById($userId, ['status' => $status]);
        if ($status === 'suspended') {
            NotificationService::push(
                $userId,
                'account',
                'Account suspended',
                'Your UniGo account has been suspended. Contact support for assistance.',
                '/support',
                'danger',
                'alert'
            );
        } else {
            NotificationService::push(
                $userId,
                'account',
                'Account reactivated',
                'Your UniGo account is active again. Welcome back!',
                '/home',
                'success',
                'check'
            );
        }
    }

    public function touchLastSeen(int $userId): void
    {
        $this->db->update('users', ['last_seen_at' => date('Y-m-d H:i:s'), 'failed_logins' => 0], 'id = ?', [$userId]);
    }

    // ------------------------------------------------------------------
    // Aggregated listings (paginated, indexed)
    // ------------------------------------------------------------------

    /**
     * @param array{search?:string,role?:string,status?:string} $filters
     * @return array{items:array<int,array<string,mixed>>,paginator:Paginator}
     */
    public function paginateUsers(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['status'])) {
            $where[] = 'u.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['role'])) {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur2 INNER JOIN roles r2 ON r2.id = ur2.role_id
                        WHERE ur2.user_id = u.id AND r2.slug = ?)';
            $params[] = $filters['role'];
        }

        return $this->paginateQuery([
            'select' => 'u.id, u.email, u.first_name, u.last_name, u.phone, u.status, u.created_at,
                         u.last_login_at, u.must_change_password,
                         GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS roles,
                         (SELECT d.id FROM drivers d WHERE d.user_id = u.id LIMIT 1) AS driver_id,
                         (SELECT o.id FROM operators o WHERE o.user_id = u.id LIMIT 1) AS operator_id',
            'from'   => 'users u',
            'joins'  => 'LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'group'  => 'u.id',
            'order'  => 'u.created_at DESC',
        ], $page, $perPage);
    }

    /**
     * @param array{search?:string,operator_id?:int,status?:string,approval_status?:string} $filters
     */
    public function paginateOperators(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(o.company_name LIKE ? OR u.email LIKE ? OR o.license_number LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }
        if (!empty($filters['approval_status'])) {
            $where[] = 'o.approval_status = ?';
            $params[] = $filters['approval_status'];
        }
        if (!empty($filters['operator_id'])) {
            $where[] = 'o.id = ?';
            $params[] = (int) $filters['operator_id'];
        }

        return $this->paginateQuery([
            'select' => 'o.*, u.email, u.first_name, u.last_name, u.status AS user_status, u.phone,
                         (SELECT COUNT(*) FROM vehicles v WHERE v.operator_id = o.id) AS vehicle_count,
                         (SELECT COUNT(*) FROM drivers d WHERE d.operator_id = o.id) AS driver_count,
                         (SELECT COUNT(*) FROM trips t WHERE t.operator_id = o.id) AS trip_count',
            'from'   => 'operators o',
            'joins'  => 'INNER JOIN users u ON u.id = o.user_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 'o.created_at DESC',
        ], $page, $perPage);
    }

    /**
     * @param array{search?:string,operator_id?:int,status?:string,rating?:string} $filters
     */
    public function paginateDrivers(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR d.license_number LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['status'])) {
            $where[] = 'd.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['operator_id'])) {
            $where[] = '(d.operator_id = ? OR EXISTS (SELECT 1 FROM operator_drivers od WHERE od.driver_id = d.id AND od.operator_id = ?))';
            $params[] = (int) $filters['operator_id'];
            $params[] = (int) $filters['operator_id'];
        }
        if (!empty($filters['rating'])) {
            $where[] = 'd.rating_avg >= ?';
            $params[] = (float) $filters['rating'];
        }

        return $this->paginateQuery([
            'select' => 'd.*, u.first_name, u.last_name, u.email, u.phone, u.status AS user_status,
                         o.company_name,
                         (SELECT v.registration_number FROM vehicles v WHERE v.driver_id = d.id LIMIT 1) AS vehicle_registration',
            'from'   => 'drivers d',
            'joins'  => 'INNER JOIN users u ON u.id = d.user_id LEFT JOIN operators o ON o.id = d.operator_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 'd.created_at DESC',
        ], $page, $perPage);
    }

    /**
     * @param array{search?:string,city?:string} $filters
     */
    public function paginatePassengers(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1'];
        $params = [];
        if (!empty($filters['search'])) {
            $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['city'])) {
            $where[] = 'p.city = ?';
            $params[] = $filters['city'];
        }

        return $this->paginateQuery([
            'select' => 'p.*, u.first_name, u.last_name, u.email, u.phone, u.status,
                         (SELECT COUNT(*) FROM bookings b WHERE b.passenger_id = u.id) AS booking_count',
            'from'   => 'passengers p',
            'joins'  => 'INNER JOIN users u ON u.id = p.user_id',
            'where'  => implode(' AND ', $where),
            'params' => $params,
            'order'  => 'u.created_at DESC',
        ], $page, $perPage);
    }

    public function updateProfile(int $userId, array $data): void
    {
        $allowed = ['first_name', 'last_name', 'phone', 'national_id', 'date_of_birth', 'gender', 'avatar'];
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $clean[$key] = $data[$key];
            }
        }
        if ($clean) {
            $this->updateById($userId, $clean);
        }
    }
}
