<?php
/**
 * UniGo — Application configuration
 * ----------------------------------
 * All environment specific values live here. Nothing in this file may contain
 * hard coded secrets in a production deployment: override any value through
 * environment variables (see .env.example / real environment).
 */

declare(strict_types=1);

return [
    // ------------------------------------------------------------------
    // Application identity
    // ------------------------------------------------------------------
    'app' => [
        'name'          => 'UniGo',
        'tagline'       => 'Integrated Smart Transport System',
        'version'       => '1.0.0',
        'env'           => getenv('UNIGO_ENV') ?: 'local',          // local | staging | production
        'debug'         => (getenv('UNIGO_DEBUG') ?: '0') === '1',
        'timezone'      => 'Africa/Kampala',
        'currency'      => 'UGX',
        'currency_symbol' => 'UGX',
        // Absolute or relative URL to the front controller.
        'base_url'      => getenv('UNIGO_BASE_URL') ?: '',           // e.g. http://localhost/unigo/public
        'reference_prefix' => [
            'booking'   => 'UG',
            'delivery'  => 'UNI-GO',
            'trip'      => 'TRP',
            'payment'   => 'PAY',
            'complaint' => 'CMP',
            'emergency' => 'SOS',
        ],
    ],

    // ------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------
    'database' => [
        'host'    => getenv('UNIGO_DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('UNIGO_DB_PORT') ?: 3306),
        'name'    => getenv('UNIGO_DB_NAME') ?: 'unigo_db',
        'user'    => getenv('UNIGO_DB_USER') ?: 'root',
        'pass'    => getenv('UNIGO_DB_PASS') !== false ? getenv('UNIGO_DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],

    // ------------------------------------------------------------------
    // Session hardening
    // ------------------------------------------------------------------
    'session' => [
        'name'            => 'UNIGOSESSID',
        'lifetime'        => 7200,      // 2 hours idle timeout
        'absolute_limit'  => 86400,     // 24 hour hard limit
        'secure'          => (getenv('UNIGO_HTTPS') ?: '0') === '1',
        'samesite'        => 'Lax',
    ],

    // ------------------------------------------------------------------
    // Security
    // ------------------------------------------------------------------
    'security' => [
        'login_max_attempts'      => 5,       // per account
        'login_window_seconds'    => 900,     // 15 minute window
        'login_ip_max_attempts'   => 20,      // per IP
        'password_min_length'     => 8,
        'password_require_mixed'  => true,
        'max_login_attempts'      => 5,
        'lockout_seconds'         => 900,
    ],

    // ------------------------------------------------------------------
    // Uploads
    // ------------------------------------------------------------------
    'uploads' => [
        'max_bytes'  => 2 * 1024 * 1024,   // 2 MB
        'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'avatars'    => 'uploads/avatars',
        'operators'  => 'uploads/operators',
    ],

    // ------------------------------------------------------------------
    // Domain settings that can also be overridden at runtime through the
    // system_settings table (see src/services/SettingsService.php)
    // ------------------------------------------------------------------
    'domain' => [
        // Demo data flag: when true the UI renders a persistent "SIMULATED DATA"
        // ribbon so simulated GPS / predictions are never mistaken for real feeds.
        'demo_mode'              => getenv('UNIGO_DEMO_MODE') === '1' && (getenv('UNIGO_ENV') ?: 'local') !== 'production',
        'max_results_per_page'   => 20,
        'max_page_size'          => 100,
        'cancellation_window_min'=> 30,   // minutes before departure
        'support_phone'          => getenv('UNIGO_SUPPORT_PHONE') ?: '',
        'support_email'          => getenv('UNIGO_SUPPORT_EMAIL') ?: '',
        'emergency_hotline'      => getenv('UNIGO_EMERGENCY_HOTLINE') ?: '',
        // Base fare + per km used when a trip has no explicit fare.
        'fare_base'              => 2000,
        'fare_per_km'            => 900,
    ],
];
