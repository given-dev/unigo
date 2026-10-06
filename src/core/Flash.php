<?php
/**
 * UniGo - Flash messages (one request lifetime session messages).
 */

declare(strict_types=1);

namespace App\Core;

final class Flash
{
    private const KEY = '_flash';

    public static function add(string $type, string $message): void
    {
        $_SESSION[self::KEY][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('error', $message);
    }

    public static function warning(string $message): void
    {
        self::add('warning', $message);
    }

    public static function info(string $message): void
    {
        self::add('info', $message);
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function pull(): array
    {
        $messages = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);
        return is_array($messages) ? $messages : [];
    }

    /** Validation errors carried across a redirect. */
    public static function withInput(array $input, array $errors): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $input = array_filter($input, static fn ($value) => is_scalar($value) || $value === null);
        $_SESSION['_old_input'] = $input;
        $_SESSION['_errors'] = $errors;
    }

    public static function errors(): array
    {
        $e = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return is_array($e) ? $e : [];
    }

    public static function old(string $key, $default = '')
    {
        $old = $_SESSION['_old_input'] ?? [];
        if (is_array($old) && array_key_exists($key, $old)) {
            $value = $old[$key];
            unset($_SESSION['_old_input'][$key]);
            if ($_SESSION['_old_input'] === []) {
                unset($_SESSION['_old_input']);
            }
            return $value;
        }
        return $default;
    }

    /**
     * Return every remembered field at once and clear the store.
     * Use this to repopulate multi-field forms; old() is for a single field.
     *
     * @return array<string,mixed>
     */
    public static function oldAll(): array
    {
        $old = $_SESSION['_old_input'] ?? [];
        unset($_SESSION['_old_input']);
        return is_array($old) ? $old : [];
    }
}
