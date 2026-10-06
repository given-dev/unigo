<?php
/**
 * UniGo - application bootstrap.
 *
 * Included by:
 *   - public/index.php          (front controller / page routes)
 *   - public/api/**\/*.php       (REST endpoints)
 *   - public/assets/...          (no)
 *   - scripts/*.php             (CLI tooling)
 *
 * Responsibilities: constants, autoloader, configuration, error handling,
 * secure session start, database connection and shared services.
 */

declare(strict_types=1);

use App\Core\ActivityLog;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\ErrorHandler;
use App\Core\Request;
use App\Services\SettingsService;

// ---------------------------------------------------------------------------
// Paths and constants
// ---------------------------------------------------------------------------
define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('DATABASE_PATH', ROOT_PATH . '/database');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('STORAGE_PATH', ROOT_PATH . '/storage');

if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}

// ---------------------------------------------------------------------------
// Autoloader (PSR-4 style: App\Services\Foo -> src/services/Foo.php)
// ---------------------------------------------------------------------------
spl_autoload_register(static function (string $class): void {
    static $classmap = [
        'App\Core\AppException'          => 'core/Exceptions.php',
        'App\Core\ValidationException'   => 'core/Exceptions.php',
        'App\Core\AuthenticationException' => 'core/Exceptions.php',
        'App\Core\AuthorizationException' => 'core/Exceptions.php',
        'App\Core\NotFoundException'     => 'core/Exceptions.php',
        'App\Core\ConflictException'     => 'core/Exceptions.php',
    ];

    if (isset($classmap[$class])) {
        require_once SRC_PATH . '/' . $classmap[$class];
        return;
    }

    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $parts = explode('\\', substr($class, 4));
    $className = array_pop($parts);
    $dir = implode('/', array_map('strtolower', $parts));

    $file = SRC_PATH . ($dir !== '' ? '/' . $dir : '') . '/' . $className . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Global helper functions (e(), money(), status_tone(), ...)
// ---------------------------------------------------------------------------
require_once SRC_PATH . '/helpers/functions.php';

// ---------------------------------------------------------------------------
// Configuration + error handling
// ---------------------------------------------------------------------------
Config::load(CONFIG_PATH . '/config.php');
date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

if (!is_dir(STORAGE_PATH)) {
    @mkdir(STORAGE_PATH . '/logs', 0775, true);
}
ErrorHandler::register(STORAGE_PATH . '/logs');

// ---------------------------------------------------------------------------
// Trust proxy headers only when explicitly enabled (rate limit integrity)
// ---------------------------------------------------------------------------
if ((bool) Config::get('app.trust_proxy', false) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $_SERVER['REMOTE_ADDR'] = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
}

// ---------------------------------------------------------------------------
// Session + authentication
// ---------------------------------------------------------------------------
Auth::startSession();
Auth::attemptRememberLogin();

// ---------------------------------------------------------------------------
// Request instance (available to every layer)
// ---------------------------------------------------------------------------
$__request = Request::capture();

// ---------------------------------------------------------------------------
// Runtime settings from the system_settings table (small table, cached per
// request; replace with Redis later for cross node caching)
// ---------------------------------------------------------------------------
try {
    SettingsService::bootstrap();
} catch (Throwable $e) {
    // The settings table is optional during the very first install.
    ErrorHandler::log('warning', 'SettingsService bootstrap skipped: ' . $e->getMessage());
}

unset($__request);
