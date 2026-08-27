<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `merge_query` Twig filter.
 *
 *   {{ current_url|merge_query({'page': 3}) }}
 *
 * Returns a copy of `current_url` with the supplied query parameters
 * merged in. Existing keys with the same name are overwritten; keys
 * that already exist and are NOT in the override are kept. A `null`
 * value removes the key (so `merge_query({'page': null})` strips the
 * `page` query parameter).
 *
 * The input is the URL string the parent template already has at
 * hand — typically `current_url` from `AbstractCrudController::list()`
 * (== `$request->getRequestUri()`). The filter calls `parse_url`
 * internally so the source can be any absolute or relative URL.
 *
 * Used by the shared list pagination/sort partials to build links that
 * preserve the current filter/sort/per-page state.
 *
 * Examples:
 *   "/admin/users?search=jane&page=2"|merge_query({'page': 5})
 *     → "/admin/users?search=jane&page=5"
 *   "/admin/users?search=jane"|merge_query({'page': 1, 'sort': 'email'})
 *     → "/admin/users?search=jane&page=1&sort=email"
 *   "/admin/users?page=2&sort=email"|merge_query({'page': null})
 *     → "/admin/users?sort=email"
 */
final class QueryStringExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('merge_query', [$this, 'mergeQuery']),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function mergeQuery(string $url, array $overrides): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
                continue;
            }
            // Scalars only — arrays would force [] brackets and
            // complicate the URL. Callers never send array values
            // through this filter.
            $query[$key] = is_scalar($value) ? (string) $value : '';
        }

        $scheme   = $parts['scheme'] ?? '';
        $host     = $parts['host'] ?? '';
        $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path     = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $prefix = ($scheme !== '' && $host !== '') ? "{$scheme}://{$host}{$port}" : '';

        return $prefix . $path . ($queryString !== '' ? '?' . $queryString : '') . $fragment;
    }
}
