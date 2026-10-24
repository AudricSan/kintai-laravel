<?php

declare(strict_types=1);

namespace kintai\UI\Utils;

final class Sort
{
    /**
     * Generate sort URL preserving current filters.
     */
    public static function url(string $key, string $current, array $filters = [], string $param = 'sort'): string
    {
        $next = ($current === $key . '_asc') ? $key . '_desc' : $key . '_asc';
        $params = array_filter([...$filters, $param => $next], fn($v) => $v !== null && $v !== 0 && $v !== '');
        return '?' . http_build_query($params);
    }

    /**
     * Get sort icon character based on current sort state.
     */
    public static function icon(string $key, string $current): string
    {
        if (str_starts_with($current, $key . '_asc'))  return ' ↑';
        if (str_starts_with($current, $key . '_desc')) return ' ↓';
        return '';
    }

    /**
     * Get aria-sort attribute value.
     */
    public static function ariaSort(string $key, string $current): string
    {
        if (str_starts_with($current, $key . '_asc'))  return 'ascending';
        if (str_starts_with($current, $key . '_desc')) return 'descending';
        return 'none';
    }

    /**
     * Check if a column is currently sorted.
     */
    public static function isActive(string $key, string $current): bool
    {
        return str_starts_with($current, $key);
    }

    /**
     * Render a full sortable <th> with link.
     */
    public static function th(string $label, string $key, string $current, array $filters = [], string $param = 'sort'): string
    {
        $icon  = self::icon($key, $current);
        $url   = self::url($key, $current, $filters, $param);
        $active = self::isActive($key, $current) ? ' sort-link--active' : '';
        $aria  = self::ariaSort($key, $current);

        return '<th aria-sort="' . $aria . '"><a href="' . htmlspecialchars($url) . '" class="sort-link' . $active . '">'
            . htmlspecialchars($label) . '<span class="sort-icon">' . $icon . '</span></a></th>';
    }
}
