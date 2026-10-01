<?php
/**
 * UniGo — HTTP request abstraction.
 *
 * All input must pass through this class. Nothing in the application should
 * read $_GET / $_POST / $_SERVER directly, which keeps sanitisation in one
 * place and makes the API layer and the page layer behave identically.
 */

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?Request $instance = null;

    private string $method;
    private string $path = ''; // resolved lazily by path()
    private array $query;
    private array $body;
    private array $files;
    private array $server;
    private ?string $rawBody = null;

    public function __construct()
    {
        $this->server = $_SERVER;
        $this->query  = $_GET;
        $this->files  = $_FILES;
        $this->method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');

        $contentType = $this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? '';

        if ($this->method === 'POST' && str_contains($contentType, 'application/json')) {
            $raw = $this->rawBody = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            $this->body = is_array($decoded) ? $decoded : [];
        } else {
            $this->body = $_POST;
        }
    }

    public static function capture(): Request
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function instance(): Request
    {
        return self::capture();
    }

    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli';
    }

    // ------------------------------------------------------------------
    // Basic metadata
    // ------------------------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /** Normalised, slash trimmed request path without the query string. */
    public function path(): string
    {
        if ($this->path === '') {
            $uri = $this->server['REQUEST_URI'] ?? '/';
            $pos = strpos($uri, '?');
            if ($pos !== false) {
                $uri = substr($uri, 0, $pos);
            }
            $uri = rawurldecode($uri);

            // Strip the base (sub) directory so that /unigo/public/trips/12 -> /trips/12
            // Compare case-insensitively: Apache may canonicalise SCRIPT_NAME to the
            // on-disk directory casing, which can differ from the requested URI.
            $script = $this->server['SCRIPT_NAME'] ?? '';
            $baseDir = str_replace('\\', '/', dirname($script));
            if ($baseDir !== '/' && $baseDir !== '.' && strncasecmp($uri, $baseDir, strlen($baseDir)) === 0) {
                $uri = substr($uri, strlen($baseDir));
            }
            if (str_starts_with($uri, '/index.php')) {
                $uri = substr($uri, strlen('/index.php'));
            }

            $this->path = '/' . trim($uri, '/');
        }
        return $this->path;
    }

    public function ip(): string
    {
        // Proxy headers are only trusted when explicitly enabled by config,
        // otherwise a client could spoof its IP and bypass rate limiting.
        $ip = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') !== '' && ($this->server['HTTPS'] ?? '') !== 'off';
    }

    public function baseUrl(): string
    {
        $configured = (string) Config::get('app.base_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $host = $this->server['HTTP_HOST'] ?? 'localhost';

        $script = $this->server['SCRIPT_NAME'] ?? '/index.php';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        // Prefer the directory casing from the actual request so generated links
        // match the address bar (Apache may report SCRIPT_NAME with on-disk case).
        $requestPath = (string) strtok((string) ($this->server['REQUEST_URI'] ?? '/'), '?');
        if ($dir !== '' && strncasecmp($requestPath, $dir, strlen($dir)) === 0) {
            $dir = substr($requestPath, 0, strlen($dir));
        }

        return ($this->isSecure() ? 'https://' : 'http://') . $host . $dir;
    }

    /** Raw request URI (path + query string) exactly as sent by the client. */
    public function uri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    /** Current query string parameters only (never the POST body). */
    public function queryParams(): array
    {
        return $this->query;
    }

    /** A single server header value, or null when absent. */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        return $value === null ? null : (string) $value;
    }

    // ------------------------------------------------------------------
    // Input access (already trimmed + XSS safe when used with e())
    // ------------------------------------------------------------------

    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        if (is_array($v)) {
            return $default;
        }
        return trim((string) $v);
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (float) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<int,string> */
    public function arr(string $key): array
    {
        $v = $this->input($key, []);
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_scalar($item)) {
                $out[] = trim((string) $item);
            }
        }
        return $out;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function rawBody(): ?string
    {
        return $this->rawBody;
    }

    // ------------------------------------------------------------------
    // Files
    // ------------------------------------------------------------------

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!$f || !isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }

    // ------------------------------------------------------------------
    // Content negotiation
    // ------------------------------------------------------------------

    public function wantsJson(): bool
    {
        if (str_contains($this->server['HTTP_ACCEPT'] ?? '', 'application/json')) {
            return true;
        }
        return $this->isApiPath();
    }

    public function isApiPath(): bool
    {
        return str_starts_with($this->path(), '/api/');
    }

    public static function expectsJson(): bool
    {
        if (Request::isCli()) {
            return true;
        }
        return self::capture()->wantsJson();
    }

    public function isAjax(): bool
    {
        return strtolower($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }
}
