<?php
/**
 * UniGo - base controller.
 *
 * Provides the small set of shared helpers every page controller needs:
 * rendering with a layout, redirects, flash messages and CSRF checks.
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Http;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

abstract class Controller
{
    protected Request $request;

    public function __construct()
    {
        $this->request = Request::instance();
    }

    /**
     * Render a page view inside a layout.
     *
     * @param array<string,mixed> $data
     */
    protected function view(string $view, array $data = [], string $layout = 'layouts/public'): void
    {
        View::render($view, $data, $layout);
    }

    /** Redirect to an application path (e.g. "/home"). */
    protected function redirect(string $path, int $status = 302): void
    {
        Http::redirect(Http::url($path), $status);
    }

    /** Redirect back to the previous page, or a fallback path. */
    protected function back(string $fallback = '/'): void
    {
        Http::back(Http::url($fallback));
    }

    /** Abort unless the request carries a valid CSRF token. */
    protected function verifyCsrf(): void
    {
        Csrf::verifyRequest();
    }

    /** Abort unless a user is signed in. */
    protected function requireLogin(): void
    {
        Auth::requireLogin();
    }

    /** Abort unless the signed-in user holds one of the given roles. */
    protected function requireRole(string ...$roles): void
    {
        Auth::requireRole(...$roles);
    }

    /** Abort if a user is already signed in (login / register pages). */
    protected function requireGuest(): void
    {
        Auth::requireGuest();
    }

    /** Emit a JSON envelope and stop. */
    protected function json(array $data = [], int $status = 200, string $message = 'OK'): void
    {
        Response::success($data, $message, $status)->send();
    }
}
