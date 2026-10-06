<?php
/**
 * UniGo - CSRF protection.
 *
 * A per-session token is issued on demand. Every state changing request
 * (POST/PUT/PATCH/DELETE) must present a matching token, either as the
 * _token field or the X-CSRF-Token header (used by fetch calls).
 */

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::SESSION_KEY];
    }

    /** Hidden input for HTML forms. */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . View::escape(self::token()) . '">';
    }

    /** Meta tag so fetch() can read it. */
    public static function meta(): string
    {
        return '<meta name="csrf-token" content="' . View::escape(self::token()) . '">';
    }

    public static function check(?string $candidate): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if ($expected === '' || $candidate === null || $candidate === '') {
            return false;
        }
        return hash_equals((string) $expected, $candidate);
    }

    public static function rotate(): void
    {
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
    }

    /**
     * Verify the current request. Throws on failure so that controllers
     * cannot forget to check.
     */
    public static function verifyRequest(): void
    {
        $request = Request::instance();
        $token = $request->str('_token')
            ?: (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        if (!self::check($token)) {
            if ($request->wantsJson()) {
                Response::error('Your session expired. Please refresh the page and try again.', 419)->send();
                exit;
            }
            Flash::error('Your session expired. Please try again.');
            Http::status(419);
            View::render('errors/error', [
                'title' => 'Refresh this page', 'code' => 419,
                'heading' => 'Your form has expired',
                'message' => 'Refresh the page to load a new form, then try again. Your changes have not been saved.',
            ], 'layouts/public');
            exit;
        }
    }
}
