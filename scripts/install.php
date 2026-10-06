<?php
/** Initialize an empty database. Never import the destructive schema over data. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require dirname(__DIR__) . '/config/config.php';
$cfg = $config['database'];
$name = (string) $cfg['name'];
if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) throw new RuntimeException('Choose a valid database name.');
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], $cfg['port']), $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$query = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=?');
$query->execute([$name]);
$existing = $query->fetchAll(PDO::FETCH_COLUMN);
$schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
preg_match_all('/CREATE TABLE `([^`]+)`/', $schema, $tables);
if ($existing) {
    $missing = array_diff($tables[1], $existing);
    if ($missing) throw new RuntimeException('Existing database is incomplete; no changes made. Missing tables: ' . implode(', ', $missing));
    echo "Database already initialized. Existing records preserved.\n";
    exit;
}
$pdo->exec(str_replace('`unigo_db`', '`' . $name . '`', $schema));
echo "Empty UniGo database initialized. No sample accounts or records were created.\n";
echo "Register your own account, then run: php scripts/admin.php --email=your-address\n";
