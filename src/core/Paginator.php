<?php
/**
 * UniGo - Pagination helper.
 *
 * Guarantees LIMIT/OFFSET is always applied so a page can never load the
 * whole table. Relevant for the 1,000 user target and beyond.
 */

declare(strict_types=1);

namespace App\Core;

final class Paginator
{
    public int $page;
    public int $perPage;
    public int $total;
    public int $lastPage;
    public int $from;
    public int $to;

    public function __construct(int $total, int $page = 1, ?int $perPage = null)
    {
        $max = (int) Config::get('domain.max_page_size', 100);
        $def = (int) Config::get('domain.max_results_per_page', 20);

        $this->perPage = max(1, min($max, $perPage ?? $def));
        $this->total = max(0, $total);
        $this->lastPage = max(1, (int) ceil($this->total / $this->perPage));
        $this->page = max(1, min($page, $this->lastPage));
        $this->from = $this->total === 0 ? 0 : (($this->page - 1) * $this->perPage) + 1;
        $this->to = min($this->total, $this->page * $this->perPage);
    }

    public static function fromRequest(int $total, string $pageParam = 'page', ?int $perPage = null): self
    {
        $page = (int) (Request::instance()->int($pageParam, 1));
        return new self($total, $page, $perPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function limit(): int
    {
        return $this->perPage;
    }

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    public function onFirstPage(): bool
    {
        return $this->page === 1;
    }

    public function onLastPage(): bool
    {
        return $this->page >= $this->lastPage;
    }

    /** Build a URL for a given page, preserving existing query parameters. */
    public function url(int $page, string $param = 'page'): string
    {
        $request = Request::instance();
        $query = $request->queryParams();
        unset($query['_token']); // a CSRF token must never end up in a link
        $query[$param] = max(1, $page);
        return strtok($request->uri(), '?') . '?' . http_build_query($query);
    }

    /** Slice an already fetched array (only used for small in-memory sets). */
    public static function slice(array $items, int $page, int $perPage): array
    {
        return array_slice($items, max(0, $page - 1) * $perPage, $perPage);
    }
}
