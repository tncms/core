<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Diagnostics;

use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Phase 9.2I — production rollout diagnostics.
 *
 * A single, read-only monitoring surface that aggregates the per-module
 * translation adapter diagnostics (Posts / Pages / taxonomy terms) and the
 * revision status into one report. It composes the existing adapter
 * `diagnostics()` methods — it never re-implements resolution and never mutates
 * anything.
 *
 * CONTRACT: this surface must NEVER break runtime. Every container resolution
 * and every downstream diagnostics call is wrapped in a Throwable boundary; a
 * failure is captured as an `error` string instead of propagating. `report()`
 * therefore always returns a well-formed array.
 */
final class TranslationRolloutDiagnostics
{
    /**
     * Certified modules and their read/write adapter container bindings.
     *
     * @var array<string, array{read: string, write: string, revision: string}>
     */
    private const MODULES = [
        'posts' => [
            'read' => 'cms.translation.posts_adapter',
            'write' => 'cms.translation.posts_write_adapter',
            'revision' => 'posts',
        ],
        'pages' => [
            'read' => 'cms.translation.pages_adapter',
            'write' => 'cms.translation.pages_write_adapter',
            'revision' => 'pages',
        ],
        'terms' => [
            'read' => 'cms.translation.terms_read_adapter',
            'write' => 'cms.translation.terms_write_adapter',
            'revision' => 'taxonomy',
        ],
    ];

    public function __construct(private readonly Container $app) {}

    /**
     * Full rollout report: certified modules + revision status. Never throws.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $modules = [];
        foreach (self::MODULES as $module => $bindings) {
            $modules[$module] = $this->moduleReport($module, $bindings);
        }

        return [
            'platform' => 'TN CMS Translation Platform v1.0',
            'phase' => '9.2I',
            'modules' => $modules,
            'revisions' => $this->revisionReport(),
        ];
    }

    /**
     * Per-module diagnostics: active driver, adapter status, entity type,
     * revision status, query metrics, and any runtime error.
     *
     * @param  array{read: string, write: string, revision: string}  $bindings
     * @return array<string, mixed>
     */
    private function moduleReport(string $module, array $bindings): array
    {
        $read = $this->safeDiagnostics($bindings['read']);
        $write = $this->safeDiagnostics($bindings['write']);

        return [
            'read_active' => (bool) ($read['active'] ?? false),
            'write_active' => (bool) ($write['active'] ?? false),
            'read_mode' => (string) ($read['mode'] ?? 'unknown'),
            'write_mode' => (string) ($write['mode'] ?? 'unknown'),
            'driver' => (string) ($read['driver'] ?? $write['driver'] ?? 'unknown'),
            'entity_type' => (string) ($read['entity_type'] ?? $read['entity'] ?? $write['entity'] ?? $module),
            'query_count' => $read['query_count'] ?? null,
            'revision_enabled' => $this->revisionEntityEnabled($bindings['revision']),
            'errors' => array_values(array_filter([
                $read['error'] ?? null,
                $read['last_error'] ?? null,
                $write['error'] ?? null,
                $write['last_error'] ?? null,
            ])),
            'read_diagnostics' => $read,
            'write_diagnostics' => $write,
        ];
    }

    /**
     * Revision platform status (master switch + per-entity flags). Never throws.
     *
     * @return array<string, mixed>
     */
    private function revisionReport(): array
    {
        try {
            /** @var array<string, mixed> $diagnostics */
            $diagnostics = $this->app->make('cms.revision')->diagnostics();

            return $diagnostics;
        } catch (Throwable $e) {
            return [
                'enabled' => (bool) config('revisions.enabled', false),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Resolve a binding and call its `diagnostics()` without ever throwing.
     *
     * @return array<string, mixed>
     */
    private function safeDiagnostics(string $binding): array
    {
        try {
            $adapter = $this->app->make($binding);

            if (! method_exists($adapter, 'diagnostics')) {
                return ['error' => 'diagnostics() unavailable on '.$binding];
            }

            /** @var array<string, mixed> $diagnostics */
            $diagnostics = $adapter->diagnostics();

            return $diagnostics;
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function revisionEntityEnabled(string $entity): bool
    {
        return (bool) config('revisions.enabled', false)
            && (bool) config('revisions.entities.'.$entity, false);
    }
}
