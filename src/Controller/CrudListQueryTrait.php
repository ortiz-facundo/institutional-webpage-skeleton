<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Query-param resolution for a paginated/sortable list. Generalized out
 * of gestion-nova's `LeadController` (`resolveSortField`/`resolveSortDirection`/
 * `resolvePage`/`resolvePerPage`) — that code had no Lead-specific logic,
 * just whitelist/clamp handling for `?sort=&direction=&page=&per_page=`.
 *
 * Used by `AbstractCrudController::list()`; not meant to be called
 * directly by concrete controllers.
 */
trait CrudListQueryTrait
{
    /**
     * Resolve the sort field to one of the whitelisted keys. Returns the
     * key itself or null when missing/unknown — both mean "no explicit
     * sort, fall back to the default order".
     *
     * @param array<string, string> $sortableColumns key => DQL-safe expr
     */
    private function resolveSortField(?string $raw, array $sortableColumns): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return array_key_exists($raw, $sortableColumns) ? $raw : null;
    }

    /**
     * Resolve the sort direction to `ASC` / `DESC` / `null`. Null covers
     * both "no field resolved" and the 3-cycle's cleared state.
     */
    private function resolveSortDirection(?string $field, ?string $raw): ?string
    {
        if ($field === null) {
            return null;
        }
        $dir = strtoupper((string) $raw);
        return match ($dir) {
            'ASC', 'DESC' => $dir,
            default       => null,
        };
    }

    private function resolvePage(?string $raw): int
    {
        return max(1, (int) ($raw ?? 1));
    }

    /**
     * @param list<int> $allowedOptions
     */
    private function resolvePerPage(?string $raw, array $allowedOptions, int $default): int
    {
        $val = (int) ($raw ?? $default);
        return in_array($val, $allowedOptions, true) ? $val : $default;
    }
}
