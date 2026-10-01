<?php
/**
 * UniGo — application exceptions.
 * Each exception carries a friendly, user safe message.
 */

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

class AppException extends RuntimeException
{
    /** @var array<string,string[]> */
    protected array $errors = [];

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, array $errors = [])
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /** @return array<string,string[]> */
    public function errors(): array
    {
        return $this->errors;
    }
}

/** 400 / 422 — input is not valid. */
final class ValidationException extends AppException
{
    public static function field(string $field, string $message): self
    {
        return new self($message, 0, null, [$field => [$message]]);
    }
}

/** 401 — no valid session. */
final class AuthenticationException extends AppException
{
}

/** 403 — signed in but not permitted. */
final class AuthorizationException extends AppException
{
}

/** 404 — unknown resource. */
final class NotFoundException extends AppException
{
}

/** 409 — duplicate / state conflict (e.g. seat already taken). */
final class ConflictException extends AppException
{
}
