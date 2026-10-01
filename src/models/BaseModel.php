<?php
/**
 * UniGo - Base model.
 *
 * Provides the small set of data access primitives every model needs, all of
 * which are parameterised. Subclasses add domain specific queries.
 *
 * Pagination is mandatory for list methods: nothing in UniGo ever loads a
 * whole table into memory.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Paginator;

abstract class BaseModel
{
    protected Database $db;
    protected string $table = '';
    protected string $primaryKey = 'id';

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return $this->db->first(
            "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = ? LIMIT 1",
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public function findBy(string $column, $value): ?array
    {
        $this->assertColumn($column);
        return $this->db->first(
            "SELECT * FROM `{$this->table}` WHERE `$column` = ? LIMIT 1",
            [$value]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function all(string $orderBy = 'id DESC', int $limit = 50): array
    {
        $this->assertColumn($orderBy);
        return $this->db->select(
            "SELECT * FROM `{$this->table}` ORDER BY `$orderBy` LIMIT " . max(1, min(500, $limit))
        );
    }

    public function exists(string $column, $value): bool
    {
        $this->assertColumn($column);
        return $this->db->exists("SELECT 1 FROM `{$this->table}` WHERE `$column` = ? LIMIT 1", [$value]);
    }

    public function count(string $where = '1', array $params = []): int
    {
        return $this->db->count("SELECT COUNT(*) FROM `{$this->table}` WHERE $where", $params);
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    public function create(array $data): int
    {
        return $this->db->insert($this->table, $data);
    }

    public function updateById(int $id, array $data): int
    {
        return $this->db->update($this->table, $data, "{$this->primaryKey} = ?", [$id]);
    }

    public function updateWhere(string $where, array $params, array $data): int
    {
        return $this->db->update($this->table, $data, $where, $params);
    }

    public function deleteById(int $id): int
    {
        return $this->db->delete($this->table, "{$this->primaryKey} = ?", [$id]);
    }

    // ------------------------------------------------------------------
    // Pagination helper shared by every admin/operator listing
    // ------------------------------------------------------------------
    /**
     * @param array{select?:string,joins?:string,where?:string,params?:array,group?:string,order?:string} $opts
     * @return array{items:array<int,array<string,mixed>>,paginator:Paginator}
     */
    protected function paginateQuery(array $opts, int $page, int $perPage): array
    {
        $select = $opts['select'] ?? '*';
        $from   = $opts['from'] ?? $this->table;
        $joins  = $opts['joins'] ?? '';
        $where  = $opts['where'] ?? '1';
        $params = $opts['params'] ?? [];
        $order  = $opts['order'] ?? "{$this->primaryKey} DESC";
        $group  = $opts['group'] ?? '';

        // The FROM clause may carry a table alias and JOINs, so it can never be
        // quoted as a bare table name. Counting over a derived table keeps the
        // total equal to the number of rows the caller actually receives.
        $countSql = "SELECT COUNT(*) FROM (SELECT 1 FROM {$from} {$joins} WHERE {$where}"
            . ($group !== '' ? " GROUP BY {$group}" : '') . ') AS cnt';
        $total = $this->db->count($countSql, $params);
        $paginator = new Paginator($total, $page, $perPage);

        $sql = "SELECT {$select} FROM {$from} {$joins} WHERE {$where}";
        if ($group !== '') {
            $sql .= " GROUP BY {$group}";
        }
        $sql .= " ORDER BY {$order} LIMIT " . $paginator->limit() . ' OFFSET ' . $paginator->offset();

        return ['items' => $this->db->select($sql, $params), 'paginator' => $paginator];
    }

    // ------------------------------------------------------------------
    // Time-series helper shared by every dashboard chart
    // ------------------------------------------------------------------

    /**
     * Aggregate a table per day and return a dense, zero-filled series.
     *
     * A raw "GROUP BY DATE(...)" query is unusable for charting: it drops days
     * with no rows (gaps) and, with only a lower bound, leaks future-dated
     * rows into a "last N days" chart. This returns exactly $days points,
     * oldest first, ending today.
     *
     * @param array<string,string> $aggregates alias => SQL aggregate expression
     * @return array<int,array<string,mixed>>
     */
    protected function dailySeriesQuery(
        string $table,
        string $dateColumn,
        array $aggregates,
        int $days,
        string $where = '',
        array $params = []
    ): array {
        $days   = max(2, min(365, $days));
        $start  = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $select = [];
        foreach ($aggregates as $alias => $expr) {
            $select[] = "COALESCE({$expr}, 0) AS `{$alias}`";
        }

        $rows = $this->db->select(
            'SELECT DATE(' . $dateColumn . ') AS day, ' . implode(', ', $select) . "
             FROM {$table}
             WHERE " . ($where !== '' ? $where : '1') . "
               AND {$dateColumn} >= ?
               AND {$dateColumn} < (CURDATE() + INTERVAL 1 DAY)
             GROUP BY DATE({$dateColumn})",
            array_merge($params, [$start])
        );

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = $row;
        }

        $series = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day    = date('Y-m-d', strtotime("-{$offset} days"));
            $source = $byDay[$day] ?? null;

            $point = ['day' => $day, 'label' => date('j M', strtotime($day))];
            foreach (array_keys($aggregates) as $alias) {
                $value = $source !== null ? (float) $source[$alias] : 0.0;
                // Keep whole numbers whole so money and counts read cleanly.
                $point[$alias] = floor($value) === $value ? (int) $value : $value;
            }
            $series[] = $point;
        }

        return $series;
    }

    /** Column / expression guard: prevents ORDER BY injection. */
    protected function assertColumn(string $column): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+(\s+(ASC|DESC))?$/', trim($column))) {
            throw new \InvalidArgumentException('Illegal column expression: ' . $column);
        }
    }

    protected function db(): Database
    {
        return $this->db;
    }

    public function table(): string
    {
        return $this->table;
    }
}
