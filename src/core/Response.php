<?php
/**
 * UniGo — JSON / HTTP response helpers.
 *
 * Every API endpoint returns the same envelope:
 *   { "success": bool, "message": string, "data": mixed, "errors": {} }
 * with meaningful HTTP status codes.
 */

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private int $status = 200;
    private array $headers = [];
    private string $body = '';

    public static function json($data = null, string $message = '', int $status = 200, array $errors = []): self
    {
        $payload = [
            'success' => $status >= 200 && $status < 300,
            'message' => $message !== '' ? $message : self::defaultMessage($status),
            'data'    => $data,
        ];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        $r = new self();
        $r->status = $status;
        $r->headers['Content-Type'] = 'application/json; charset=utf-8';
        $r->body = (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        return $r;
    }

    public static function success($data = null, string $message = 'Request completed successfully.', int $status = 200): self
    {
        return self::json($data, $message, $status);
    }

    public static function created($data = null, string $message = 'Created successfully.'): self
    {
        return self::json($data, $message, 201);
    }

    public static function error(string $message, int $status = 400, array $errors = [], $data = null): self
    {
        $r = self::json($data, $message, $status, $errors);
        $r->status = $status;
        return $r;
    }

    public static function validation(array $errors, string $message = 'Please correct the highlighted fields.'): self
    {
        return self::json(null, $message, 422, $errors);
    }

    public static function unauthenticated(string $message = 'Please sign in to continue.'): self
    {
        return self::json(null, $message, 401);
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action.'): self
    {
        return self::json(null, $message, 403);
    }

    public static function notFound(string $message = 'The requested resource was not found.'): self
    {
        return self::json(null, $message, 404);
    }

    public static function conflict(string $message = 'That action conflicts with the current state.'): self
    {
        return self::json(null, $message, 409);
    }

    public static function noContent(): self
    {
        $r = new self();
        $r->status = 204;
        return $r;
    }

    /** Paginated collection envelope. */
    public static function paginate(array $items, int $total, int $page, int $perPage, string $message = ''): self
    {
        return self::json([
            'items' => $items,
            'meta'  => [
                'total'        => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'last_page'    => max(1, (int) ceil($total / max(1, $perPage))),
                'from'         => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to'           => min($total, $page * $perPage),
                'has_pages'    => $total > $perPage,
            ],
        ], $message !== '' ? $message : 'Records loaded.');
    }

    public function withHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
            if ($this->status !== 204) {
                header('X-Content-Type-Options: nosniff');
                header('Referrer-Policy: strict-origin-when-cross-origin');
            }
        }
        echo $this->body;
    }

    private static function defaultMessage(int $status): string
    {
        $map = [
            200 => 'Request completed successfully.',
            201 => 'Created successfully.',
            204 => 'Done.',
            400 => 'The request could not be understood.',
            401 => 'Please sign in to continue.',
            403 => 'You are not allowed to perform this action.',
            404 => 'The requested resource was not found.',
            409 => 'That action conflicts with the current state.',
            419 => 'Your session expired. Please refresh and try again.',
            422 => 'Please correct the highlighted fields.',
            429 => 'Too many requests. Please slow down and try again shortly.',
            500 => 'Something went wrong. Please try again.',
        ];
        return $map[$status] ?? 'Request completed.';
    }
}
