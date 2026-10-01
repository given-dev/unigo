<?php
/**
 * UniGo - HTTP / redirect / URL helpers.
 */

declare(strict_types=1);

namespace App\Core;

final class Http
{
    public static function status(int $code): void
    {
        if (!headers_sent()) {
            http_response_code($code);
        }
    }

    public static function redirect(string $url, int $status = 302): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, $status);
        } else {
            echo '<script>location.replace(' . json_encode($url) . ');</script>';
        }
        exit;
    }

    public static function back(string $fallback = '/'): void
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref !== '' && str_starts_with($ref, self::hostOnly())) {
            self::redirect($ref);
        }
        self::redirect($fallback);
    }

    private static function hostOnly(): string
    {
        $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    /** Absolute URL for a path relative to the application base. */
    public static function url(string $path = '/'): string
    {
        if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, 'mailto:') || str_starts_with($path, 'tel:')) {
            return $path;
        }
        return Request::instance()->baseUrl() . '/' . ltrim($path, '/');
    }

    /** Path only (no scheme/host) - used by the client side router. */
    public static function path(string $path = '/'): string
    {
        return '/' . ltrim($path, '/');
    }

    /** Convenience wrapper used heavily inside views. */
    public static function e($value): string
    {
        return View::escape($value);
    }

    public static function asset(string $path): string
    {
        $rel = ltrim($path, '/');
        $file = PUBLIC_PATH . '/' . $rel;
        $version = is_file($file) ? (string) filemtime($file) : (string) Config::get('app.version', '1');
        return self::url($rel) . '?v=' . $version;
    }
}
