<?php
/**
 * UniGo - Login rate limiting.
 *
 * Two independent buckets protect the login endpoint:
 *   - per account (email)  : stops password spraying against one user
 *   - per IP               : stops distributed / single host brute force
 *
 * Storage is the login_attempts table so limits survive restarts and can be
 * moved to Redis later (see RateLimiter::hit comment).
 */

declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    public const SCOPE_LOGIN = 'login';

    /**
     * @return array{allowed:bool,attempts:int,retry_after:int,message:string}
     */
    public static function check(string $scope, string $identifier): array
    {
        $db = Database::instance();
        $window = (int) Config::get('security.login_window_seconds', 900);
        $maxAttempts = $scope === self::SCOPE_LOGIN
            ? (int) Config::get('security.login_max_attempts', 5)
            : 60;

        $attempts = $db->count(
            'SELECT COUNT(*) FROM login_attempts
             WHERE scope = ? AND identifier_hash = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)',
            [$scope, hash('sha256', $identifier), $window]
        );

        if ($attempts >= $maxAttempts) {
            $retry = (int) $db->value(
                'SELECT TIMESTAMPDIFF(SECOND, MIN(attempted_at), NOW())
                 FROM login_attempts
                 WHERE scope = ? AND identifier_hash = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)',
                [$scope, hash('sha256', $identifier), $window]
            );
            $retry = max(1, $window - (int) $retry);
            return [
                'allowed'     => false,
                'attempts'    => $attempts,
                'retry_after' => $retry,
                'message'     => 'Too many sign-in attempts. Please try again in ' . ceil($retry / 60) . ' minute(s).',
            ];
        }

        return ['allowed' => true, 'attempts' => $attempts, 'retry_after' => 0, 'message' => ''];
    }

    public static function hit(string $scope, string $identifier, bool $success): void
    {
        $db = Database::instance();
        $db->insert('login_attempts', [
            'scope'           => $scope,
            'identifier_hash' => hash('sha256', $identifier),
            'ip_address'      => Request::isCli() ? 'cli' : Request::instance()->ip(),
            'was_successful'  => $success ? 1 : 0,
            'attempted_at'    => date('Y-m-d H:i:s'),
        ]);

        // Opportunistic cleanup keeps the table small without a cron job.
        if (random_int(1, 50) === 1) {
            $db->run('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 7 DAY)');
        }
    }

    public static function clear(string $scope, string $identifier): void
    {
        Database::instance()->delete(
            'login_attempts',
            'scope = ? AND identifier_hash = ?',
            [$scope, hash('sha256', $identifier)]
        );
    }

    public static function attemptsLeft(string $scope, string $identifier): int
    {
        $state = self::check($scope, $identifier);
        if (!$state['allowed']) {
            return 0;
        }
        $max = (int) Config::get('security.login_max_attempts', 5);
        return max(0, $max - $state['attempts']);
    }
}
