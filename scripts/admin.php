<?php
/** Grant the initial administrator role to an existing registered account. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
use App\Core\{ActivityLog, Database};
use App\Models\UserModel;
$options = getopt('', ['email:']);
$email = strtolower(trim((string) ($options['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/admin.php --email=your-registered-email\n");
    exit(1);
}
Database::instance()->transaction(function ($db) use ($email): void {
    $role = $db->first("SELECT id FROM roles WHERE slug='admin' FOR UPDATE");
    if (!$role) throw new RuntimeException('Initialize the database first.');
    $user = (new UserModel())->findByEmail($email);
    if (!$user || $user['status'] !== 'active') throw new RuntimeException('Register an active account first.');
    if ($db->exists('SELECT 1 FROM user_roles WHERE user_id=? AND role_id=?', [$user['id'], $role['id']])) {
        echo "This account is already an administrator.\n";
        return;
    }
    if ($db->exists('SELECT 1 FROM user_roles WHERE role_id=?', [$role['id']])) throw new RuntimeException('An administrator already exists. Use its Users workspace to add another.');
    (new UserModel())->assignRole((int) $user['id'], 'admin');
    ActivityLog::record('account.admin_bootstrap', (int) $user['id'], 'user', 'Initial administrator granted through local CLI', (int) $user['id']);
    echo "Administrator access granted. Sign in with your own password.\n";
});
