<?php
/**
 * UniGo — Error handler.
 *
 * Responsibilities:
 *   - Convert PHP warnings/notices into log entries, never into user output
 *   - Render friendly error pages for browser requests
 *   - Render friendly JSON envelopes for API requests
 *   - Keep technical detail (stack traces, SQL) in the server log only
 */

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class ErrorHandler
{
    private static string $logFile = '';
    private static bool $registered = false;

    public static function register(string $logDir): void
    {
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        self::$logFile = rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . 'unigo-' . date('Y-m') . '.log';

        if (self::$registered) {
            return;
        }
        self::$registered = true;

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function logFile(): string
    {
        return self::$logFile;
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        if (self::$logFile === '') {
            return;
        }
        $line = sprintf(
            "[%s] %s: %s %s%s",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
            PHP_EOL
        );
        @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
    }

    public static function logCritical(string $message, array $context = []): void
    {
        self::log('critical', $message, $context);
    }

    public static function handleException(Throwable $e): void
    {
        self::log('exception', get_class($e) . ': ' . $e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => Config::isDebug() ? $e->getTraceAsString() : null,
        ]);

        $isApi = Request::expectsJson();

        if (Request::isCli()) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }

        if ($isApi) {
            Response::error(
                'Something went wrong. Please try again.',
                500,
                Config::isDebug() ? ['exception' => get_class($e), 'message' => $e->getMessage(), 'line' => $e->getLine()] : []
            )->send();
            return;
        }

        Http::status(500);
        View::render('errors/error', [
            'title'   => 'Something went wrong',
            'code'    => 500,
            'heading' => 'Something went wrong',
            'message' => 'We could not complete your request. The issue has been logged and our team will review it.',
            'debug'   => Config::isDebug() ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() : null,
        ]);
    }

    public static function handleShutdown(): void
    {
        $err = error_get_last();
        if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        self::log('fatal', $err['message'], ['file' => $err['file'], 'line' => $err['line']]);

        if (!headers_sent() && !Request::isCli() && !Request::expectsJson()) {
            Http::status(500);
            echo '<h1>Something went wrong</h1><p>Please try again.</p>';
        }
    }

    /**
     * Convert an unknown throwable into a safe, friendly message set.
     * Domain specific exception classes override these defaults.
     */
    public static function friendly(Throwable $e): array
    {
        if ($e instanceof ValidationException) {
            return ['Your request could not be completed.', $e->getMessage(), 422, $e->errors()];
        }
        if ($e instanceof AuthorizationException) {
            return ['You are not allowed to perform this action.', $e->getMessage(), 403];
        }
        if ($e instanceof AuthenticationException) {
            return ['Please sign in to continue.', $e->getMessage(), 401];
        }
        if ($e instanceof NotFoundException) {
            return ['The requested resource was not found.', $e->getMessage(), 404];
        }
        if ($e instanceof ConflictException) {
            return ['That action conflicts with the current state.', $e->getMessage(), 409];
        }
        return ['Something went wrong. Please try again.', $e->getMessage(), 500];
    }
}
