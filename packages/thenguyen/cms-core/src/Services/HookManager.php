<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Support\Hooks\HookDefinition;

/**
 * Actions & Filters registry (v1.0.0-beta.7.1.11) — a WordPress-inspired hook
 * system so plugins and themes can extend CMS behaviour without touching core.
 *
 * - An ACTION runs registered callbacks at a named point (side effects only):
 *   `do_action('cms.content.saved', $content)`.
 * - A FILTER threads a value through registered callbacks, each returning a
 *   (possibly) modified value: `$title = apply_filters('cms.content.title', $title, $content)`.
 *
 * Both share the same registry mechanics: callbacks run in ascending priority
 * order, ties broken by registration order, and each callback receives at most
 * `$acceptedArgs` arguments. A throwing callback is caught and reported — a
 * broken extension hook never crashes the admin or the frontend, and (for
 * filters) the value passes through unchanged.
 *
 * This is NOT a webhook / external-HTTP system; it is purely in-process.
 */
class HookManager
{
    /**
     * @var array<string, array<int, array{callback: callable, priority: int, accepted_args: int, seq: int, meta: array<string, mixed>}>>
     */
    private array $actions = [];

    /**
     * @var array<string, array<int, array{callback: callable, priority: int, accepted_args: int, seq: int, meta: array<string, mixed>}>>
     */
    private array $filters = [];

    /**
     * Hook-point documentation (v1.0.0-beta.7.1.11.1). Purely descriptive —
     * hooks run with or without a definition.
     *
     * @var array<string, HookDefinition>
     */
    private array $definitions = [];

    /** Monotonic counter giving stable ordering for equal priorities. */
    private int $sequence = 0;

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $meta  Optional registration metadata
     *                                       (source, source_slug, label).
     */
    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1, array $meta = []): void
    {
        $this->actions[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => max(0, $acceptedArgs),
            'seq' => $this->sequence++,
            'meta' => $this->normalizeMeta($meta),
        ];
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        foreach ($this->sorted($this->actions[$hook] ?? []) as $entry) {
            try {
                $sliced = $entry['accepted_args'] === 0 ? [] : array_slice($args, 0, $entry['accepted_args']);
                ($entry['callback'])(...$sliced);
            } catch (\Throwable $e) {
                $this->reportHookFailure('action', $hook, $e);
            }
        }
    }

    /**
     * Run an action and CAPTURE everything its callbacks echo, returning it as a
     * string. Backs render_hook() so themes can place `{!! render_hook(...) !!}`.
     * Output buffering is always balanced, even when a callback throws.
     */
    public function captureAction(string $hook, mixed ...$args): string
    {
        ob_start();

        try {
            $this->doAction($hook, ...$args);
        } finally {
            $output = ob_get_clean();
        }

        return $output === false ? '' : $output;
    }

    public function hasAction(string $hook): bool
    {
        return ! empty($this->actions[$hook]);
    }

    public function removeAction(string $hook, callable|string|null $callback = null): void
    {
        $this->actions = $this->removeFrom($this->actions, $hook, $callback);
    }

    /**
     * Registered action hook names mapped to their callback count. Discovery /
     * debug only — never exposes the callbacks themselves.
     *
     * @return array<string, int>
     */
    public function actions(): array
    {
        return $this->summaryOf($this->actions);
    }

    // ---------------------------------------------------------------------
    // Filters
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $meta  Optional registration metadata
     *                                       (source, source_slug, label).
     */
    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1, array $meta = []): void
    {
        $this->filters[$hook][] = [
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => max(1, $acceptedArgs),
            'seq' => $this->sequence++,
            'meta' => $this->normalizeMeta($meta),
        ];
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->sorted($this->filters[$hook] ?? []) as $entry) {
            try {
                // The value is always the first argument; extra context args fill
                // the remaining accepted slots.
                $passed = array_slice($args, 0, max(0, $entry['accepted_args'] - 1));
                $value = ($entry['callback'])($value, ...$passed);
            } catch (\Throwable $e) {
                $this->reportHookFailure('filter', $hook, $e);
                // Value passes through unchanged on failure.
            }
        }

        return $value;
    }

    public function hasFilter(string $hook): bool
    {
        return ! empty($this->filters[$hook]);
    }

    public function removeFilter(string $hook, callable|string|null $callback = null): void
    {
        $this->filters = $this->removeFrom($this->filters, $hook, $callback);
    }

    /**
     * @return array<string, int>
     */
    public function filters(): array
    {
        return $this->summaryOf($this->filters);
    }

    // ---------------------------------------------------------------------
    // Hook definitions (registry / documentation)
    // ---------------------------------------------------------------------

    /**
     * Document an action hook point. Accepts a ready {@see HookDefinition} or a
     * hook name plus a meta array (description, arguments, since, group, …).
     *
     * @param  array<string, mixed>  $meta
     */
    public function defineAction(HookDefinition|string $definition, array $meta = []): void
    {
        $this->define(HookDefinition::TYPE_ACTION, $definition, $meta);
    }

    /**
     * Document a filter hook point. See {@see defineAction()}.
     *
     * @param  array<string, mixed>  $meta
     */
    public function defineFilter(HookDefinition|string $definition, array $meta = []): void
    {
        $this->define(HookDefinition::TYPE_FILTER, $definition, $meta);
    }

    /**
     * All registered hook definitions, keyed by hook name.
     *
     * @return array<string, HookDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function definition(string $hook): ?HookDefinition
    {
        return $this->definitions[$hook] ?? null;
    }

    /**
     * @return array<string, HookDefinition>
     */
    public function definedActions(): array
    {
        return array_filter($this->definitions, static fn (HookDefinition $d): bool => $d->isAction());
    }

    /**
     * @return array<string, HookDefinition>
     */
    public function definedFilters(): array
    {
        return array_filter($this->definitions, static fn (HookDefinition $d): bool => $d->isFilter());
    }

    public function definitionCount(): int
    {
        return count($this->definitions);
    }

    // ---------------------------------------------------------------------
    // Safe summaries (discovery / health — never expose callbacks)
    // ---------------------------------------------------------------------

    /**
     * Per-hook action summary: callback count, distinct priorities, and a count
     * of callbacks per declared source. NEVER includes the callbacks themselves.
     *
     * @return array<string, array{count: int, priorities: array<int, int>, sources: array<string, int>}>
     */
    public function actionSummary(): array
    {
        return $this->summaryWithMeta($this->actions);
    }

    /**
     * @return array<string, array{count: int, priorities: array<int, int>, sources: array<string, int>}>
     */
    public function filterSummary(): array
    {
        return $this->summaryWithMeta($this->filters);
    }

    /**
     * Total number of registered callbacks across all actions and filters.
     */
    public function callbackCount(): int
    {
        $count = 0;

        foreach ($this->actions as $entries) {
            $count += count($entries);
        }

        foreach ($this->filters as $entries) {
            $count += count($entries);
        }

        return $count;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $meta
     */
    private function define(string $type, HookDefinition|string $definition, array $meta): void
    {
        if (is_string($definition)) {
            $def = new HookDefinition(
                name: $definition,
                type: $type,
                description: is_string($meta['description'] ?? null) ? $meta['description'] : '',
                arguments: is_array($meta['arguments'] ?? null) ? $meta['arguments'] : [],
                returnType: isset($meta['return_type']) ? (string) $meta['return_type'] : null,
                since: isset($meta['since']) ? (string) $meta['since'] : null,
                source: is_string($meta['source'] ?? null) ? $meta['source'] : 'core',
                group: isset($meta['group']) ? (string) $meta['group'] : null,
            );
        } else {
            $def = $meta === [] ? $definition : $definition->mergeMeta($meta);
        }

        // Duplicate definitions: last wins, but report for visibility.
        if (isset($this->definitions[$def->name])) {
            $this->reportDefinitionConflict($def->name);
        }

        $this->definitions[$def->name] = $def;
    }

    /**
     * Keep only the known, safe metadata keys.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function normalizeMeta(array $meta): array
    {
        $out = [];

        if (isset($meta['source']) && is_string($meta['source']) && $meta['source'] !== '') {
            $out['source'] = $meta['source'];
        }

        if (isset($meta['source_slug']) && is_string($meta['source_slug']) && $meta['source_slug'] !== '') {
            $out['source_slug'] = $meta['source_slug'];
        }

        if (isset($meta['label']) && is_string($meta['label']) && $meta['label'] !== '') {
            $out['label'] = $meta['label'];
        }

        return $out;
    }

    /**
     * @param  array<string, array<int, array{priority: int, meta: array<string, mixed>}>>  $registry
     * @return array<string, array{count: int, priorities: array<int, int>, sources: array<string, int>}>
     */
    private function summaryWithMeta(array $registry): array
    {
        $out = [];

        foreach ($registry as $hook => $entries) {
            $priorities = [];
            $sources = [];

            foreach ($entries as $entry) {
                $priorities[] = $entry['priority'];
                $source = is_string($entry['meta']['source'] ?? null) ? $entry['meta']['source'] : 'unknown';
                $sources[$source] = ($sources[$source] ?? 0) + 1;
            }

            $priorities = array_values(array_unique($priorities));
            sort($priorities);

            $out[$hook] = [
                'count' => count($entries),
                'priorities' => $priorities,
                'sources' => $sources,
            ];
        }

        return $out;
    }

    private function reportDefinitionConflict(string $hook): void
    {
        try {
            \Illuminate\Support\Facades\Log::info(
                "TN CMS hook definition [{$hook}] redefined; last definition wins.",
                ['cms_hook' => $hook],
            );
        } catch (\Throwable) {
            // Never let a redefinition notice break registration.
        }
    }

    /**
     * @param  array<int, array{callback: callable, priority: int, accepted_args: int, seq: int}>  $entries
     * @return array<int, array{callback: callable, priority: int, accepted_args: int, seq: int}>
     */
    private function sorted(array $entries): array
    {
        usort($entries, static function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority'] ?: $a['seq'] <=> $b['seq'];
        });

        return $entries;
    }

    /**
     * @param  array<string, array<int, array{callback: callable, priority: int, accepted_args: int, seq: int}>>  $registry
     * @return array<string, array<int, array{callback: callable, priority: int, accepted_args: int, seq: int}>>
     */
    private function removeFrom(array $registry, string $hook, callable|string|null $callback): array
    {
        if (! isset($registry[$hook])) {
            return $registry;
        }

        if ($callback === null) {
            unset($registry[$hook]);

            return $registry;
        }

        $registry[$hook] = array_values(array_filter(
            $registry[$hook],
            fn (array $entry): bool => ! $this->sameCallback($entry['callback'], $callback),
        ));

        if ($registry[$hook] === []) {
            unset($registry[$hook]);
        }

        return $registry;
    }

    private function sameCallback(callable $stored, callable|string $given): bool
    {
        if (is_string($given)) {
            return is_string($stored) && $stored === $given;
        }

        return $stored === $given;
    }

    /**
     * @param  array<string, array<int, mixed>>  $registry
     * @return array<string, int>
     */
    private function summaryOf(array $registry): array
    {
        $out = [];

        foreach ($registry as $hook => $entries) {
            $out[$hook] = count($entries);
        }

        return $out;
    }

    private function reportHookFailure(string $kind, string $hook, \Throwable $e): void
    {
        // Reported to the application's error handler (logged), never rendered to
        // the page. Debug context is included only when app.debug is on.
        $context = ['cms_hook' => $hook, 'cms_hook_kind' => $kind];

        if (config('app.debug')) {
            $context['exception'] = (string) $e;
        }

        try {
            \Illuminate\Support\Facades\Log::warning(
                "TN CMS {$kind} hook [{$hook}] callback failed: {$e->getMessage()}",
                $context,
            );
        } catch (\Throwable) {
            // Logging must never itself break a request.
        }
    }
}
