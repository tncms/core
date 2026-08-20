<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

/**
 * Admin Form Hook Bridge (v1.0.0-beta.7.1.12.2).
 *
 * A thin, WordPress-inspired seam that lets plugins extend TN CMS-owned admin
 * forms WITHOUT touching the Filament resources. Two concerns:
 *
 *  - SCHEMA: reshape the resource's existing component array. Applies
 *    `cms.form.schema` then the per-alias `cms.form.schema.{alias}` filter.
 *  - REGIONS: contribute EXTRA components appended full-width beneath the form.
 *    Applies `cms.form.regions` then `cms.form.regions.{alias}`.
 *
 * Aliases are TN CMS-owned and stable: `post`, `page`, `term`
 * (category/tag), `media`.
 *
 * Every filter receives `($value, ?string $modelClass, ?string $alias, array
 * $context)`. Listeners that only want the value keep the default acceptedArgs
 * of 1 and never see the extra context — so existing callbacks are safe. The
 * bridge is defensive: a missing hook layer, a throwing callback, or a non-array
 * return degrades to the ORIGINAL components (the form always renders).
 */
class FormHookBridge
{
    /**
     * Filter a resource's form component array.
     *
     * @param  array<int, mixed>  $components
     * @param  array<string, mixed>  $context
     * @return array<int, mixed>
     */
    public function applySchema(array $components, ?string $modelClass = null, ?string $alias = null, array $context = []): array
    {
        $result = $this->coerce($this->filter('cms.form.schema', $components, $modelClass, $alias, $context), $components);

        if ($alias !== null && $alias !== '') {
            $result = $this->coerce($this->filter("cms.form.schema.{$alias}", $result, $modelClass, $alias, $context), $result);
        }

        return array_values($result);
    }

    /**
     * Resolve the extra "region" components to append beneath a resource form.
     *
     * @param  array<int, mixed>  $regions  Seed value (usually []).
     * @param  array<string, mixed>  $context
     * @return array<int, mixed>
     */
    public function applyRegions(array $regions, ?string $modelClass = null, ?string $alias = null, array $context = []): array
    {
        $result = $this->coerce($this->filter('cms.form.regions', $regions, $modelClass, $alias, $context), $regions);

        if ($alias !== null && $alias !== '') {
            $result = $this->coerce($this->filter("cms.form.regions.{$alias}", $result, $modelClass, $alias, $context), $result);
        }

        return array_values($result);
    }

    /**
     * Keep the filtered value only when it is still an array; otherwise fall back
     * to the last-good array so a broken listener can never blank the form.
     *
     * @param  array<int, mixed>  $fallback
     * @return array<int, mixed>
     */
    private function coerce(mixed $value, array $fallback): array
    {
        return is_array($value) ? $value : $fallback;
    }

    /**
     * Run a form filter safely. A broken listener (or a value the hook layer is
     * not ready for) never breaks the admin form — the incoming value passes
     * through unchanged.
     *
     * @param  array<string, mixed>  $context
     */
    private function filter(string $hook, mixed $value, ?string $modelClass, ?string $alias, array $context): mixed
    {
        try {
            if (function_exists('apply_filters')) {
                return apply_filters($hook, $value, $modelClass, $alias, $context);
            }
        } catch (\Throwable) {
            // Fall through to the unmodified value.
        }

        return $value;
    }
}
