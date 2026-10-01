<?php
/**
 * UniGo - View renderer.
 *
 * Renders PHP templates from src/views with optional layout wrapping and
 * named sections (used to inject per page CSS / JavaScript).
 */

declare(strict_types=1);

namespace App\Core;

final class View
{
    /** @var array<string,string> */
    private static array $sections = [];
    private static ?string $currentSection = null;

    public static function render(string $view, array $data = [], string $layout = 'layouts/app'): void
    {
        echo self::capture($view, $data, $layout);
    }

    public static function capture(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($view, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, array_merge($data, ['content' => $content]));
    }

    public static function partial(string $view, array $data = []): string
    {
        $file = self::resolve($view);
        if (!is_file($file)) {
            throw new NotFoundException('View not found: ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    /** Include a reusable component from views/partials. */
    public static function component(string $name, array $data = []): void
    {
        echo self::partial('partials/' . $name, $data);
    }

    /**
     * Include a view only for the value it returns.
     *
     * Used by "provider" views such as partials/nav-items.php, which return an
     * array instead of markup. Nothing is echoed and no buffer is opened.
     *
     * @return mixed whatever the view file returns
     */
    public static function provider(string $view, array $data = [])
    {
        $file = self::resolve($view);
        if (!is_file($file)) {
            throw new NotFoundException('View not found: ' . $view);
        }
        extract($data, EXTR_SKIP);
        return require $file;
    }

    private static function resolve(string $view): string
    {
        $view = str_replace(['..', "\0", '\\'], ['', '', '/'], $view);
        $view = ltrim($view, '/');
        if (!str_ends_with($view, '.php')) {
            $view .= '.php';
        }
        return SRC_PATH . '/views/' . $view;
    }

    // ---- named sections ----

    public static function start(string $name): void
    {
        self::$currentSection = $name;
        ob_start();
    }

    public static function stop(): void
    {
        if (self::$currentSection !== null) {
            self::$sections[self::$currentSection] = (self::$sections[self::$currentSection] ?? '') . (string) ob_get_clean();
            self::$currentSection = null;
        }
    }

    public static function section(string $name): string
    {
        return self::$sections[$name] ?? '';
    }

    public static function yieldSection(string $name): void
    {
        echo self::section($name);
    }

    public static function hasSection(string $name): bool
    {
        return !empty(self::$sections[$name]);
    }

    /**
     * Output escaping. Every dynamic value printed in a view must use this.
     */
    public static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escape for use inside a JS string / JSON blob. */
    public static function json($value): string
    {
        return (string) json_encode(
            $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
    }
}
