<?php
/**
 * UniGo - global view/helper functions.
 *
 * Small, dependency free helpers used across views. Everything that touches
 * output goes through e() to guarantee escaping.
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Http;
use App\Core\View;

if (!function_exists('e')) {
    /** HTML escape. Used for every dynamic value rendered in a view. */
    function e($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return Http::url($path);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return Http::asset($path);
    }
}

if (!function_exists('app_name')) {
    function app_name(): string
    {
        return (string) Config::get('app.name', 'UniGo');
    }
}

if (!function_exists('money')) {
    /** Format money with thousands separators. */
    function money($amount, bool $withSymbol = true): string
    {
        $amount = (float) ($amount ?? 0);
        $formatted = number_format($amount, 0, '.', ',');
        return $withSymbol ? rtrim((string) Config::get('app.currency_symbol', 'UGX')) . ' ' . $formatted : $formatted;
    }
}

if (!function_exists('number_short')) {
    /** 12500 -> 12.5K (used in dashboard tiles to stay compact). */
    function number_short($n): string
    {
        $n = (float) $n;
        if ($n >= 1_000_000_000) {
            return round($n / 1_000_000_000, 1) . 'B';
        }
        if ($n >= 1_000_000) {
            return round($n / 1_000_000, 1) . 'M';
        }
        if ($n >= 10_000) {
            return round($n / 1000, 1) . 'K';
        }
        if ($n >= 1000) {
            return number_format($n, 0, '.', ',');
        }
        return (string) (int) $n;
    }
}

if (!function_exists('time_ago')) {
    /** Relative timestamp, e.g. "3 min ago". */
    function time_ago(?string $datetime): string
    {
        if (!$datetime) {
            return '-';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '-';
        }
        $diff = time() - $ts;
        if ($diff < 5) {
            return 'just now';
        }
        if ($diff < 60) {
            return $diff . ' sec ago';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' min ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' hr ago';
        }
        if ($diff < 604800) {
            return floor($diff / 86400) . ' d ago';
        }
        return date('j M Y', $ts);
    }
}

if (!function_exists('dt')) {
    /** Format a datetime for display. */
    function dt(?string $datetime, string $format = 'j M Y, H:i'): string
    {
        if (!$datetime) {
            return '-';
        }
        $ts = strtotime($datetime);
        return $ts === false ? '-' : date($format, $ts);
    }
}

if (!function_exists('time_only')) {
    function time_only(?string $datetime): string
    {
        if (!$datetime) {
            return '-';
        }
        $ts = strtotime($datetime);
        return $ts === false ? '-' : date('H:i', $ts);
    }
}

if (!function_exists('duration_minutes')) {
    /** "1h 25m" from minutes. */
    function duration_minutes(?int $minutes): string
    {
        $m = max(0, (int) $minutes);
        $h = intdiv($m, 60);
        $r = $m % 60;
        if ($h === 0) {
            return $r . 'm';
        }
        return $h . 'h ' . ($r > 0 ? $r . 'm' : '');
    }
}

if (!function_exists('initials')) {
    function initials(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
        return mb_strtoupper($first . $last);
    }
}

if (!function_exists('status_tone')) {
    /**
     * Map a domain status string onto a design-system badge tone.
     * Keeps badge colours consistent everywhere without repeating if/else.
     */
    function status_tone(?string $status): string
    {
        $s = strtolower(trim((string) $status));
        $map = [
            'active' => 'success', 'confirmed' => 'success', 'completed' => 'success',
            'delivered' => 'success', 'successful' => 'success', 'approved' => 'success',
            'resolved' => 'success', 'available' => 'success', 'paid' => 'success',
            'on_trip' => 'info', 'in_transit' => 'info', 'boarding' => 'info', 'info' => 'info',
            'scheduled' => 'info', 'assigned' => 'info', 'responding' => 'info',
            'pending' => 'warning', 'boardings' => 'warning', 'maintenance' => 'warning',
            'investigating' => 'warning', 'new' => 'warning', 'created' => 'warning',
            'picked_up' => 'warning', 'processing' => 'warning',
            'cancelled' => 'danger', 'failed' => 'danger', 'suspended' => 'danger',
            'rejected' => 'danger', 'no_show' => 'danger', 'blocked' => 'danger',
            'inactive' => 'neutral', 'draft' => 'neutral', 'closed' => 'neutral',
            'open' => 'neutral', 'expired' => 'neutral', 'refunded' => 'neutral',
        ];
        return $map[$s] ?? 'neutral';
    }
}

if (!function_exists('status_label')) {
    function status_label(?string $status): string
    {
        $s = strtolower(trim((string) $status));
        return ucwords(str_replace('_', ' ', $s));
    }
}

if (!function_exists('transport_icon')) {
    /** Inline SVG icon name for a transport type. */
    function transport_icon(?string $type): string
    {
        return match (strtolower((string) $type)) {
            'bus'          => 'bus',
            'electric_bus' => 'bolt',
            'taxi'         => 'car',
            'boda'         => 'moto',
            'shared_ride'  => 'users',
            'truck', 'delivery' => 'truck',
            'boat'         => 'boat',
            default        => 'car',
        };
    }
}

if (!function_exists('transport_label')) {
    function transport_label(?string $type): string
    {
        return status_label($type);
    }
}

if (!function_exists('is_demo_mode')) {
    function is_demo_mode(): bool
    {
        return (bool) Config::get('domain.demo_mode', false);
    }
}

if (!function_exists('stars')) {
    /** Render 5 stars with a fractional fill via width percentage. */
    function stars(float $rating): string
    {
        $pct = max(0, min(100, $rating / 5 * 100));
        return '<span class="stars" role="img" aria-label="Rated ' . number_format($rating, 1) . ' out of 5">'
             . '<span class="stars__base">' . str_repeat('<i class="icon">star</i>', 5) . '</span>'
             . '<span class="stars__fill" style="width:' . $pct . '%">' . str_repeat('<i class="icon">star</i>', 5) . '</span>'
             . '</span>';
    }
}

if (!function_exists('json_attr')) {
    /** Safe JSON for a data-* attribute. */
    function json_attr($value): string
    {
        return View::json($value);
    }
}

if (!function_exists('nav_active')) {
    /** Compare a path prefix against the current request path. */
    function nav_active(string $prefix, string $current, bool $exact = false): string
    {
        if ($exact) {
            return rtrim($current, '/') === rtrim($prefix, '/') ? ' is-active' : '';
        }
        $prefix = rtrim($prefix, '/');
        if ($prefix === '') {
            return $current === '/' ? ' is-active' : '';
        }
        return ($current === $prefix || str_starts_with($current, $prefix . '/')) ? ' is-active' : '';
    }
}

if (!function_exists('highlight')) {
    /** Very small, injection safe search-term highlighter. */
    function highlight(?string $text, ?string $term): string
    {
        $safe = e($text);
        $term = trim((string) $term);
        if ($term === '' || mb_strlen($term) < 2) {
            return $safe;
        }
        $needle = e(preg_quote($term, '/'));
        return (string) preg_replace('/(' . $needle . ')/i', '<mark>$1</mark>', $safe);
    }
}
