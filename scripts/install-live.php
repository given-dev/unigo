<?php
/** Creates a NEW live database and administrator; never overwrites existing data. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit('Command line only.');
$options = getopt('', ['database:', 'email:', 'name:', 'support-phone:']);
$database = (string) ($options['database'] ?? 'unigo_live');
$email = strtolower(trim((string) ($options['email'] ?? '')));
$password = (string) getenv('UNIGO_ADMIN_PASSWORD');
if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $database) || !filter_var($email, FILTER_VALIDATE_EMAIL) || str_ends_with($email,'@unigo.test')) {
    fwrite(STDERR, "Usage: php scripts/install-live.php --database=unigo_live --email=YOUR_EMAIL --name=YOUR_NAME\nSet UNIGO_ADMIN_PASSWORD to your own strong password first.\n");
    exit(1);
}
$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) { fwrite(STDERR, "An .env file already exists. Keep it safe and configure a separate installation; nothing was changed.\n"); exit(1); }
putenv('UNIGO_DEMO_MODE=0');
putenv('UNIGO_ENV=production');
putenv('UNIGO_DB_NAME=' . $database);
require dirname(__DIR__) . '/src/bootstrap.php';
use App\Core\{Auth, Config, Database, Validator};
use App\Models\UserModel;
use App\Services\SettingsService;
if (!Validator::isStrongPassword($password)) { fwrite(STDERR, Validator::passwordHint() . "\nNothing was changed.\n"); exit(1); }
$cfg = Config::get('database');
try {
    $pdo = new PDO('mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';charset=utf8mb4', $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');
    $stmt->execute([$database]);
    if ((int) $stmt->fetchColumn() !== 0) throw new RuntimeException('That database already exists. Choose a new name; nothing was changed.');
    $schema = str_replace('`unigo_db`', '`' . $database . '`', file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    $pdo->exec($schema);
    $adminId = (new UserModel())->createUser([
        'email'=>$email, 'password_hash'=>Auth::hashPassword($password),
        'first_name'=>(string) ($options['name'] ?? 'Administrator'), 'last_name'=>'', 'status'=>'active',
    ], ['admin']);
    SettingsService::set('demo_mode', '0');
    SettingsService::set('support_email', $email);
    SettingsService::set('support_phone', (string) ($options['support-phone'] ?? ''));
    SettingsService::set('emergency_hotline', '');
    $settings = ['UNIGO_ENV'=>'production','UNIGO_DEMO_MODE'=>'0','UNIGO_DEBUG'=>'0',
        'UNIGO_DB_HOST'=>$cfg['host'],'UNIGO_DB_PORT'=>(string)$cfg['port'],'UNIGO_DB_NAME'=>$database,
        'UNIGO_DB_USER'=>$cfg['user'],'UNIGO_DB_PASS'=>$cfg['pass'],
        'UNIGO_BASE_URL'=>(string) Config::get('app.base_url',''),
        'UNIGO_HTTPS'=>Config::get('session.secure',false) ? '1' : '0'];
    $lines=[];
    foreach ($settings as $key=>$value) {
        if (preg_match('/[\r\n]/', $value)) throw new RuntimeException('Environment values must be single lines.');
        $lines[] = $key . '=' . json_encode($value, JSON_UNESCAPED_SLASHES);
    }
    $handle = fopen($envPath, 'x');
    if (!$handle) throw new RuntimeException('Could not create .env. Your new database exists; configure UNIGO_DB_NAME manually.');
    fwrite($handle, implode("\n", $lines) . "\n"); fclose($handle); @chmod($envPath, 0600);
    echo "Live database $database installed. Sign in with $email and your chosen password.\nCreate real operators, approve them, add fleet/routes/drivers and schedule trips. No example data was copied.\n";
} catch (Throwable $e) { fwrite(STDERR, "Install failed: " . $e->getMessage() . "\nExisting databases were not replaced.\n"); exit(1); }
