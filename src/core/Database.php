<?php
/**
 * UniGo — Database gateway.
 *
 * A thin, safe wrapper around PDO. Every statement in the application goes
 * through this class, which guarantees:
 *   - real prepared statements (no string interpolation of user input)
 *   - exceptions on error so that failures never leak SQL to the browser
 *   - transaction helpers with savepoint-free nested commit protection
 *   - emulated prepares disabled (native server side prepares = faster)
 *
 * Supports MySQL / MariaDB. Charset is always utf8mb4 so that emoji and
 * international passenger names store correctly.
 */

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final class Database
{
    private static ?Database $instance = null;

    private PDO $pdo;
    private int $txDepth = 0;
    private bool $txFailed = false;

    /** @var array<int,array{sql:string,ms:float}> slow query log (debug only) */
    private array $slowQueries = [];

    private function __construct(array $cfg)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            $this->pdo = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_PERSISTENT         => false,
            ]);
            // Keep PHP DATETIME writes and SQL NOW() on the same clock.
            $this->pdo->exec('SET time_zone = ' . $this->pdo->quote(date('P')));
        } catch (PDOException $e) {
            // Log to the server only. The user sees a friendly message.
            ErrorHandler::logCritical('Database connection failed: ' . $e->getMessage());
            throw new \RuntimeException('Unable to connect to the UniGo database.');
        }
    }

    public static function instance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self((array) Config::get('database', []));
        }
        return self::$instance;
    }

    /** Test / CLI helper. */
    public static function setInstance(?Database $db): void
    {
        self::$instance = $db;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    // ------------------------------------------------------------------
    // Query helpers
    // ------------------------------------------------------------------

    /**
     * Execute a prepared statement.
     *
     * @param string               $sql     SQL written by developers only, using ? placeholders
     * @param array<string|int,mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $start = microtime(true);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->normaliseParams($params));
        } catch (PDOException $e) {
            ErrorHandler::logCritical('Query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw new \RuntimeException('The request could not be completed. Please try again.', 0, $e);
        }

        $ms = (microtime(true) - $start) * 1000;
        if ($ms > 150) {
            $this->slowQueries[] = ['sql' => preg_replace('/\s+/', ' ', $sql), 'ms' => round($ms, 1)];
        }

        return $stmt;
    }

    /** @return array<int,array<string,mixed>> */
    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = [], $default = null)
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    public function count(string $sql, array $params = []): int
    {
        return (int) $this->value($sql, $params, 0);
    }

    public function insert(string $table, array $data): int
    {
        $this->assertIdentifier($table);
        $cols = array_keys($data);
        foreach ($cols as $c) {
            $this->assertIdentifier($c);
        }
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $cols) . '`',
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->run($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $this->assertIdentifier($table);
        $sets = [];
        foreach (array_keys($data) as $c) {
            $this->assertIdentifier($c);
            $sets[] = '`' . $c . '` = ?';
        }
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return $this->run($sql, array_merge(array_values($data), $whereParams))->rowCount();
    }

    public function delete(string $table, string $where, array $whereParams = []): int
    {
        $this->assertIdentifier($table);
        return $this->run("DELETE FROM `$table` WHERE $where", $whereParams)->rowCount();
    }

    public function exists(string $sql, array $params = []): bool
    {
        return (bool) $this->value($sql, $params, 0);
    }

    // ------------------------------------------------------------------
    // Transactions (used by booking + payment + seat locking)
    // ------------------------------------------------------------------

    /**
     * Run a closure inside a transaction. Nested calls join the outer
     * transaction so that services can compose safely.
     *
     * @template T
     * @param  callable(Database):T $callback
     * @return T
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->txDepth === 0) {
            $this->pdo->beginTransaction();
            $this->txFailed = false;
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        if ($this->txDepth === 0) {
            throw new \LogicException('No transaction is active.');
        }
        if ($this->txDepth === 1) {
            if ($this->txFailed) {
                $this->pdo->rollBack();
                $this->txDepth = 0;
                $this->txFailed = false;
                throw new \RuntimeException('The transaction was rolled back because a nested operation failed.');
            }
            $this->pdo->commit();
        }
        $this->txDepth--;
    }

    public function rollback(): void
    {
        if ($this->txDepth === 0) {
            return;
        }
        $this->txFailed = true;
        if ($this->txDepth === 1) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
        $this->txDepth = max(0, $this->txDepth - 1);
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Bind booleans correctly for MySQL and cast numerics so that
     * DECIMAL columns are not returned as strings where a float is expected.
     *
     * @param array<string|int,mixed> $params
     * @return array<string|int,mixed>
     */
    private function normaliseParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? 1 : 0;
            } elseif (is_int($v) || is_float($v) || $v === null) {
                // pass through
            } elseif (is_array($v) || is_object($v)) {
                $v = json_encode($v);
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Identifiers can never come from user input; this guard makes that an
     * explicit, testable rule instead of a convention.
     */
    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException('Illegal SQL identifier: ' . $identifier);
        }
    }

    /** @return array<int,array{sql:string,ms:float}> */
    public function slowQueries(): array
    {
        return $this->slowQueries;
    }

    /** Server version string, used on the admin health page. */
    public function serverVersion(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    }
}
