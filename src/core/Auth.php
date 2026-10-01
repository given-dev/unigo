<?php
/**
 * UniGo - Authentication & authorisation.
 *
 * Responsibilities
 *   - secure session bootstrap (httponly, samesite, idle + absolute timeout)
 *   - password hashing with password_hash() / password_verify()
 *   - multi role support (passenger, driver, operator, admin, authority)
 *   - fine grained authorisation guards used by controllers AND api endpoints
 *   - "remember me" via a rotated opaque token (never a raw password)
 */

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private const SESSION_USER   = '_auth_user_id';
    private const SESSION_ROLES  = '_auth_roles';
    private const SESSION_FP     = '_auth_fingerprint';
    private const SESSION_SEEN   = '_auth_last_seen';
    private const SESSION_START  = '_auth_started_at';

    private static ?array $cachedUser = null;
    private static ?array $cachedRoles = null;

    // ------------------------------------------------------------------
    // Session bootstrap
    // ------------------------------------------------------------------

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || Request::isCli()) {
            return;
        }

        $cfg = (array) Config::get('session', []);

        session_name((string) ($cfg['name'] ?? 'UNIGOSESSID'));
        session_set_cookie_params([
            'lifetime' => 0,                 // session cookie; idle timeout enforced server side
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) ($cfg['secure'] ?? false),
            'httponly' => true,              // JavaScript cannot read the session id
            'samesite' => (string) ($cfg['samesite'] ?? 'Lax'),
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_start();

        self::enforceTimeout();
        self::bindFingerprint();
    }

    /** Idle + absolute session expiry. */
    private static function enforceTimeout(): void
    {
        $cfg = (array) Config::get('session', []);
        $lifetime = (int) ($cfg['lifetime'] ?? 7200);
        $absolute = (int) ($cfg['absolute_limit'] ?? 86400);

        if (empty($_SESSION[self::SESSION_USER])) {
            return;
        }

        $lastSeen = (int) ($_SESSION[self::SESSION_SEEN] ?? time());
        $started  = (int) ($_SESSION[self::SESSION_START] ?? time());

        if ((time() - $lastSeen) > $lifetime || (time() - $started) > $absolute) {
            self::destroy();
            Flash::info('You were signed out because your session expired.');
        } else {
            $_SESSION[self::SESSION_SEEN] = time();
        }
    }

    /**
     * Detect session hijacking: bind the session to a coarse client
     * fingerprint (user agent). An IP change alone must NOT log the user out
     * because mobile networks frequently change IP mid journey.
     */
    private static function bindFingerprint(): void
    {
        $ua = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (empty($_SESSION[self::SESSION_FP])) {
            $_SESSION[self::SESSION_FP] = $ua;
            return;
        }
        if (!hash_equals((string) $_SESSION[self::SESSION_FP], $ua)) {
            ErrorHandler::log('warning', 'Session fingerprint mismatch - session terminated', [
                'user_id' => $_SESSION[self::SESSION_USER] ?? null,
            ]);
            self::destroy();
            session_start();
        }
    }

    // ------------------------------------------------------------------
    // Credentials
    // ------------------------------------------------------------------

    public static function hashPassword(string $plain): string
    {
        // PASSWORD_DEFAULT currently resolves to bcrypt; the cost can be raised
        // later without invalidating existing hashes.
        return password_hash($plain, PASSWORD_DEFAULT, ['cost' => 12]);
    }

    /**
     * Verify credentials.
     *
     * @return array{ok:bool,user:?array,message:string}
     */
    public static function attempt(string $email, string $password, bool $remember = false): array
    {
        $email = strtolower(trim($email));
        $ip = Request::isCli() ? 'cli' : Request::instance()->ip();

        // 1. per account limit
        $accountLimit = RateLimiter::check(RateLimiter::SCOPE_LOGIN, 'email:' . $email);
        if (!$accountLimit['allowed']) {
            RateLimiter::hit(RateLimiter::SCOPE_LOGIN, 'email:' . $email, false);
            return ['ok' => false, 'user' => null, 'message' => $accountLimit['message']];
        }

        // 2. per IP limit
        $ipLimit = RateLimiter::check(RateLimiter::SCOPE_LOGIN, 'ip:' . $ip);
        if (!$ipLimit['allowed']) {
            return ['ok' => false, 'user' => null, 'message' => $ipLimit['message']];
        }

        $user = Database::instance()->first(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [$email]
        );

        // Constant-ish work whether or not the account exists, so response
        // time does not reveal which emails are registered.
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';

        if (!password_verify($password, (string) $hash) || !$user) {
            RateLimiter::hit(RateLimiter::SCOPE_LOGIN, 'email:' . $email, false);
            RateLimiter::hit(RateLimiter::SCOPE_LOGIN, 'ip:' . $ip, false);
            ActivityLog::record(ActivityLog::LOGIN_FAILED, (int) ($user['id'] ?? 0), 'user', 'Failed sign-in for ' . $email, (int) ($user['id'] ?? 0));
            return ['ok' => false, 'user' => null, 'message' => 'Invalid email or password.'];
        }

        if ($user['status'] === 'suspended') {
            RateLimiter::hit(RateLimiter::SCOPE_LOGIN, 'email:' . $email, false);
            ActivityLog::record(ActivityLog::ACCOUNT_SUSPENDED, (int) $user['id'], 'user', 'Sign-in blocked: suspended account', (int) $user['id']);
            return ['ok' => false, 'user' => null, 'message' => 'This account has been suspended. Please contact support.'];
        }

        if ($user['status'] === 'inactive') {
            RateLimiter::hit(RateLimiter::SCOPE_LOGIN, 'email:' . $email, false);
            return ['ok' => false, 'user' => null, 'message' => 'This account is not active yet. Please check your email for an invitation.'];
        }

        // Rehash transparently if the cost factor changed.
        if (password_needs_rehash((string) $hash, PASSWORD_DEFAULT, ['cost' => 12])) {
            Database::instance()->update('users', ['password_hash' => self::hashPassword($password)], 'id = ?', [(int) $user['id']]);
        }

        self::login($user, $remember);

        RateLimiter::clear(RateLimiter::SCOPE_LOGIN, 'email:' . $email);
        ActivityLog::record(ActivityLog::LOGIN, (int) $user['id'], 'user', 'Signed in from ' . $ip);

        return ['ok' => true, 'user' => self::user(), 'message' => 'Signed in successfully.'];
    }

    /** Establish the authenticated session. */
    public static function login(array $user, bool $remember = false): void
    {
        self::regenerateId();
        $_SESSION[self::SESSION_USER]  = (int) $user['id'];
        $_SESSION[self::SESSION_ROLES] = self::rolesForUser((int) $user['id']);
        $_SESSION[self::SESSION_SEEN]  = time();
        $_SESSION[self::SESSION_START] = time();
        $_SESSION[self::SESSION_FP]    = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        self::$cachedUser = null;
        self::$cachedRoles = null;

        Database::instance()->update('users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'failed_logins' => 0,
        ], 'id = ?', [(int) $user['id']]);

        if ($remember) {
            self::issueRememberToken((int) $user['id']);
        }
    }

    public static function logout(): void
    {
        $userId = self::id();
        if ($userId) {
            self::revokeRememberToken($userId);
            ActivityLog::record(ActivityLog::LOGOUT, $userId, 'user', 'Signed out');
        }
        self::destroy();
    }

    /** Full teardown: clears session data, cookie and regenerate on next use. */
    private static function destroy(): void
    {
        self::$cachedUser = null;
        self::$cachedRoles = null;

        $_SESSION = [];

        if (!headers_sent() && ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private static function regenerateId(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    // ------------------------------------------------------------------
    // State
    // ------------------------------------------------------------------

    public static function check(): bool
    {
        return self::id() !== null;
    }

    public static function guest(): bool
    {
        return !self::check();
    }

    public static function id(): ?int
    {
        if (Request::isCli()) {
            // CLI helpers may impersonate a user explicitly via Auth::asUser()
            return self::$cachedUser['id'] ?? null;
        }
        $id = $_SESSION[self::SESSION_USER] ?? null;
        return $id === null ? null : (int) $id;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$cachedUser !== null) {
            return self::$cachedUser;
        }
        $id = self::id();
        if ($id === null) {
            return null;
        }
        $db = Database::instance();
        $user = $db->first(
            'SELECT u.*,
                    GROUP_CONCAT(r.slug ORDER BY r.slug SEPARATOR ",") AS role_names
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE u.id = ?
             GROUP BY u.id',
            [$id]
        );
        if ($user && $user['status'] === 'suspended' && !Request::isCli()) {
            self::logout();
            return null;
        }
        self::$cachedUser = $user;
        return $user;
    }

    public static function name(): string
    {
        $u = self::user();
        return $u ? trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) : 'Guest';
    }

    public static function firstName(): string
    {
        $u = self::user();
        return $u ? (string) ($u['first_name'] ?? 'there') : 'there';
    }

    public static function avatar(): ?string
    {
        $u = self::user();
        return $u && !empty($u['avatar']) ? Http::url($u['avatar']) : null;
    }

    public static function email(): ?string
    {
        $u = self::user();
        return $u ? (string) $u['email'] : null;
    }

    /** @return array<int,string> */
    public static function roles(): array
    {
        if (self::$cachedRoles !== null) {
            return self::$cachedRoles;
        }
        $id = self::id();
        if ($id === null) {
            return self::$cachedRoles = [];
        }
        $cached = $_SESSION[self::SESSION_ROLES] ?? null;
        if (is_array($cached) && $cached !== []) {
            return self::$cachedRoles = $cached;
        }
        return self::$cachedRoles = self::rolesForUser($id);
    }

    /**
     * Role slugs held by a user, e.g. ["passenger"].
     *
     * The slug is the stable machine identifier ("passenger"); the role name is
     * a human label ("Passenger") and must never be used for authorization.
     *
     * @return array<int,string>
     */
    public static function rolesForUser(int $userId): array
    {
        $rows = Database::instance()->select(
            'SELECT r.slug FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ?',
            [$userId]
        );
        return array_values(array_unique(array_column($rows, 'slug')));
    }

    public static function hasRole(string ...$roles): bool
    {
        $mine = self::roles();
        foreach ($roles as $r) {
            if (in_array($r, $mine, true)) {
                return true;
            }
        }
        return false;
    }

    public static function isAdmin(): bool
    {
        return self::hasRole('admin');
    }

    public static function isPassenger(): bool
    {
        return self::hasRole('passenger');
    }

    public static function isDriver(): bool
    {
        return self::hasRole('driver');
    }

    public static function isOperator(): bool
    {
        return self::hasRole('operator');
    }

    public static function isAuthority(): bool
    {
        return self::hasRole('authority');
    }

    /** Roles that manage a company or the whole system. */
    public static function isStaff(): bool
    {
        return self::hasRole('admin', 'operator', 'authority');
    }

    /** Landing page for the signed in user. */
    public static function homeRoute(): string
    {
        if (self::hasRole('admin')) {
            return '/admin/dashboard';
        }
        if (self::hasRole('authority')) {
            return '/authority/dashboard';
        }
        if (self::hasRole('operator')) {
            return '/operator/dashboard';
        }
        if (self::hasRole('driver')) {
            return '/driver/dashboard';
        }
        return '/home';
    }

    /** Identifier of the caller's own profile row in a subtype table. */
    public static function profileId(string $table): ?int
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }
        $v = Database::instance()->value("SELECT id FROM `$table` WHERE user_id = ?", [$id]);
        return $v === null ? null : (int) $v;
    }

    public static function operatorId(): ?int
    {
        return self::profileId('operators');
    }

    public static function driverId(): ?int
    {
        return self::profileId('drivers');
    }

    public static function passengerId(): ?int
    {
        return self::profileId('passengers');
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    /** Require any authenticated session (JSON aware). */
    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        if (Request::instance()->wantsJson()) {
            Response::unauthenticated()->send();
            exit;
        }
        $_SESSION['_intended'] = Request::instance()->uri();
        Flash::info('Please sign in to continue.');
        Http::redirect(Http::url('/login'));
    }

    /** Require one of the given roles (JSON aware). */
    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!self::hasRole(...$roles)) {
            self::deny('You do not have permission to open this page.');
        }
    }

    public static function requireGuest(): void
    {
        if (self::check()) {
            Http::redirect(Http::url(self::homeRoute()));
        }
    }

    /** 403 handling for both HTML and JSON callers. */
    public static function deny(string $message = 'You are not allowed to perform this action.'): void
    {
        if (Request::instance()->wantsJson()) {
            Response::forbidden($message)->send();
            exit;
        }
        Http::status(403);
        View::render('errors/error', [
            'title'   => 'Access denied',
            'code'    => 403,
            'heading' => 'Access denied',
            'message' => $message,
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // Remember me (opaque, hashed at rest, rotates on use)
    // ------------------------------------------------------------------

    private static function issueRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $cookie = $selector . ':' . $validator;

        Database::instance()->insert('remember_tokens', [
            'user_id'       => $userId,
            'selector'      => $selector,
            'validator_hash'=> hash('sha256', $validator),
            'expires_at'    => date('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        setcookie('unigo_remember', $cookie, [
            'expires'  => time() + 60 * 60 * 24 * 30,
            'path'     => '/',
            'secure'   => (bool) Config::get('session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function revokeRememberToken(int $userId): void
    {
        Database::instance()->delete('remember_tokens', 'user_id = ?', [$userId]);
        if (!headers_sent()) {
            setcookie('unigo_remember', '', ['expires' => time() - 42000, 'path' => '/']);
        }
    }

    /** Called from the front controller to resume a remembered session. */
    public static function attemptRememberLogin(): void
    {
        if (self::check() || empty($_COOKIE['unigo_remember'])) {
            return;
        }
        $parts = explode(':', (string) $_COOKIE['unigo_remember']);
        if (count($parts) !== 2) {
            return;
        }
        [$selector, $validator] = $parts;
        $row = Database::instance()->first(
            'SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW()',
            [$selector]
        );
        if (!$row || !hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            return;
        }
        $user = Database::instance()->first('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]);
        if ($user && $user['status'] === 'active') {
            Database::instance()->delete('remember_tokens', 'id = ?', [(int) $row['id']]);
            self::login($user);
            self::issueRememberToken((int) $user['id']);
        }
    }

    // ------------------------------------------------------------------
    // Password helpers
    // ------------------------------------------------------------------

    public static function changePassword(int $userId, string $current, string $new): bool
    {
        $user = Database::instance()->first('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!$user || !password_verify($current, (string) $user['password_hash'])) {
            return false;
        }
        Database::instance()->update('users', [
            'password_hash'         => self::hashPassword($new),
            'must_change_password'  => 0,
            'password_changed_at'   => date('Y-m-d H:i:s'),
        ], 'id = ?', [$userId]);

        ActivityLog::record(ActivityLog::PASSWORD_CHANGED, $userId, 'user', 'Password changed');
        return true;
    }

    /** CLI only: run a callback as a specific user (seeder, console tools). */
    public static function asUser(int $userId, callable $callback)
    {
        $previous = self::$cachedUser;
        self::$cachedUser = Database::instance()->first('SELECT * FROM users WHERE id = ?', [$userId]);
        self::$cachedRoles = self::rolesForUser($userId);
        try {
            return $callback(self::$cachedUser);
        } finally {
            self::$cachedUser = $previous;
            self::$cachedRoles = null;
        }
    }
}
