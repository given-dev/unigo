<?php
/**
 * UniGo - Router.
 *
 * Tiny pattern router for page controllers. Supports /trips/{id} style
 * placeholders. API endpoints are plain PHP files under /public/api which
 * keeps them trivially cache/CDN friendly and avoids one giant switch.
 */

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string,array<int,array{regex:string,handler:mixed}>> */
    private array $routes = [
        'GET'    => [],
        'POST'   => [],
        'PUT'    => [],
        'PATCH'  => [],
        'DELETE' => [],
    ];

    public function get(string $pattern, $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, $handler): void
    {
        $this->routes[$method][] = [$this->compile($pattern), $handler];
    }

    private function compile(string $pattern): string
    {
        $escaped = preg_quote(rtrim($pattern, '/'), '#');
        $escaped = preg_replace('/\\\\\{([a-zA-Z_]+)\\\\\}/', '(?P<$1>[^/]+)', $escaped);
        return '#^' . $escaped . '/?$#';
    }

    /**
     * @return array{handler:mixed,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as [$regex, $handler]) {
            if (preg_match($regex, $path, $m)) {
                $params = [];
                foreach ($m as $k => $v) {
                    if (is_string($k)) {
                        $params[$k] = urldecode($v);
                    }
                }
                return ['handler' => $handler, 'params' => $params];
            }
        }
        return null;
    }
}
