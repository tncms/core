<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operator sitemap policy (CORE-SITEMAP-ADMIN-R1), bound as
 * `cms.sitemap_settings`. Stored in `cms_settings`; every default preserves
 * the behaviour before this phase (enabled, nothing excluded).
 *
 * Exclusion only shapes Core's sitemap output: it never marks a page noindex,
 * never touches a plugin's registration, routes or data, and is keyed by the
 * registry's plugin identity (the plugin slug), never by a display name.
 */
final class SitemapSettings
{
    public const ENABLED = 'seo.sitemap_enabled';

    public const EXCLUDED_TYPES = 'seo.sitemap_excluded_types';

    public const EXCLUDED_PROVIDERS = 'seo.sitemap_excluded_providers';

    public const CHUNK_SIZE = 'seo.sitemap_chunk_size';

    /** Core sitemap types an operator may exclude; `home` always stays. */
    public const TOGGLEABLE_TYPES = ['pages', 'posts', 'categories', 'tags'];

    public function enabled(): bool
    {
        return settings(self::ENABLED) !== false;
    }

    /** @return list<string> */
    public function excludedTypes(): array
    {
        return array_values(array_intersect(self::TOGGLEABLE_TYPES, $this->stringList(settings(self::EXCLUDED_TYPES))));
    }

    /** @return list<string> plugin slugs */
    public function excludedProviders(): array
    {
        $ids = array_values(array_filter(
            $this->stringList(settings(self::EXCLUDED_PROVIDERS)),
            static fn (string $id): bool => $id !== SitemapProviderRegistry::CORE,
        ));
        sort($ids, SORT_STRING);

        return $ids;
    }

    public function typeIncluded(string $provider, string $type): bool
    {
        return $provider !== SitemapProviderRegistry::CORE || ! in_array($type, $this->excludedTypes(), true);
    }

    public function providerIncluded(string $provider): bool
    {
        return ! in_array($provider, $this->excludedProviders(), true);
    }

    /**
     * Admin listing: Core, every provider registered in this process and
     * every stored exclusion whose plugin is not registered now (so it can
     * be seen and cleared). Reads cached manifests only; never builds one.
     *
     * @return list<array{id: string, name: string, plugin: string|null, registered: bool, active: bool, included: bool, excludable: bool, children: int|null, url_capacity: int|null, manifest: string|null, built_at: int|null, failed_at: int|null, error: string|null}>
     */
    public function providerRows(): array
    {
        $manager = app('cms.sitemap');
        $registered = app('cms.sitemap_providers')->registered();
        $extensions = app('cms.extension');
        $ids = array_values(array_unique([SitemapProviderRegistry::CORE, ...array_keys($registered), ...$this->excludedProviders()]));
        $rows = [];

        foreach ($ids as $id) {
            $core = $id === SitemapProviderRegistry::CORE;
            $plugin = $core ? null : $this->safe(static fn () => $extensions->findPlugin($id));
            $isRegistered = $core || isset($registered[$id]);
            $active = $core || (bool) $this->safe(static fn () => $extensions->isPluginActive($id));
            $peek = $isRegistered ? $manager->peek($id) : null;
            $status = $manager->status($id);

            $rows[] = [
                'id' => $id,
                'name' => $core ? 'TN CMS Core' : ($plugin?->name ?? $id),
                'plugin' => $core ? null : $id,
                'registered' => $isRegistered,
                'active' => $active,
                'included' => $this->providerIncluded($id),
                'excludable' => ! $core,
                'children' => $peek['children'] ?? null,
                'url_capacity' => isset($peek['children']) ? $peek['children'] * $manager->chunkSize() : null,
                'manifest' => $peek['source'] ?? null,
                'built_at' => $status['built_at'] ?? null,
                'failed_at' => $status['failed_at'] ?? null,
                'error' => $status['error'] ?? null,
            ];
        }

        return $rows;
    }

    /** @return array{enabled: bool, chunk_size: int, types: array<string, bool>, providers: array<string, bool>} */
    public function formState(): array
    {
        $types = [];
        foreach (self::TOGGLEABLE_TYPES as $type) {
            $types[$type] = $this->typeIncluded(SitemapProviderRegistry::CORE, $type);
        }

        $providers = [];
        foreach ($this->providerRows() as $row) {
            if ($row['excludable']) {
                $providers[$row['id']] = $row['included'];
            }
        }

        return [
            'enabled' => $this->enabled(),
            'chunk_size' => app('cms.sitemap')->chunkSize(),
            'types' => $types,
            'providers' => $providers,
        ];
    }

    /**
     * Server-side validation of a submitted state. Only known keys are
     * accepted; `$knownProviders` is the listing's ids (Core is never
     * excludable, an unknown id is a forgery).
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $knownProviders
     * @return array<string, string> field => message
     */
    public function validate(array $input, array $knownProviders): array
    {
        $errors = [];

        if (array_key_exists('enabled', $input) && ! is_bool($input['enabled'])) {
            $errors['enabled'] = tn_trans('Choose on or off.');
        }

        if (array_key_exists('chunk_size', $input)) {
            $chunk = $input['chunk_size'];
            $int = is_int($chunk) ? $chunk : (is_string($chunk) && preg_match('/^[0-9]{1,9}$/', $chunk) === 1 ? (int) $chunk : null);

            if ($int === null || $int < SitemapManager::MIN_CHUNK || $int > 50000) {
                $errors['chunk_size'] = tn_trans('Enter a whole number from :min to :max.', ['min' => SitemapManager::MIN_CHUNK, 'max' => '50000']);
            }
        }

        if (array_key_exists('types', $input) && ! $this->validToggleMap($input['types'], self::TOGGLEABLE_TYPES)) {
            $errors['types'] = tn_trans('Unknown content type.');
        }

        $excludable = array_values(array_diff($knownProviders, [SitemapProviderRegistry::CORE]));
        if (array_key_exists('providers', $input) && ! $this->validToggleMap($input['providers'], $excludable)) {
            $errors['providers'] = tn_trans('Unknown sitemap provider.');
        }

        return $errors;
    }

    /**
     * Persist a VALIDATED state, invalidate the affected manifests and log
     * the change (Core has no generic audit store; the log is the record).
     * `invalidated` is false when the settings were stored but the affected
     * manifests could not be invalidated: the caller must not report a clean
     * save (exclusions still apply because they are filtered at serve time).
     *
     * @param  array<string, mixed>  $input
     * @return array{changes: array<string, array{0: mixed, 1: mixed}>, invalidated: bool}
     */
    public function save(array $input): array
    {
        $before = $this->snapshot();

        if (array_key_exists('enabled', $input)) {
            settings()->set(self::ENABLED, (bool) $input['enabled'], 'boolean');
        }

        if (array_key_exists('chunk_size', $input)) {
            settings()->set(self::CHUNK_SIZE, (int) $input['chunk_size'], 'integer');
        }

        if (is_array($input['types'] ?? null)) {
            $excluded = [];
            foreach (self::TOGGLEABLE_TYPES as $type) {
                if (($input['types'][$type] ?? true) === false) {
                    $excluded[] = $type;
                }
            }
            settings()->set(self::EXCLUDED_TYPES, $excluded, 'array');
        }

        if (is_array($input['providers'] ?? null)) {
            $excluded = $this->excludedProviders();
            foreach ($input['providers'] as $id => $included) {
                $excluded = $included === false
                    ? [...$excluded, (string) $id]
                    : array_values(array_diff($excluded, [(string) $id]));
            }
            $excluded = array_values(array_unique($excluded));
            sort($excluded, SORT_STRING);
            settings()->set(self::EXCLUDED_PROVIDERS, $excluded, 'array');
        }

        $after = $this->snapshot();
        $changes = [];
        foreach ($after as $key => $value) {
            if ($value !== $before[$key]) {
                $changes[$key] = [$before[$key], $value];
            }
        }

        $invalidated = true;
        if ($changes !== []) {
            $affected = [SitemapProviderRegistry::CORE];
            if (isset($changes['providers'])) {
                $affected = [...$affected, ...array_merge(...array_map(static fn ($v) => (array) $v, $changes['providers']))];
            }
            $invalidated = app('cms.sitemap')->invalidateProviders($affected);

            Log::info('TN CMS sitemap settings changed', [
                'user_id' => auth()->id(),
                'changes' => $changes,
                'invalidated' => $invalidated,
            ]);
        }

        return ['changes' => $changes, 'invalidated' => $invalidated];
    }

    /** @return array{enabled: bool, chunk_size: int, types: list<string>, providers: list<string>} */
    private function snapshot(): array
    {
        return [
            'enabled' => $this->enabled(),
            'chunk_size' => app('cms.sitemap')->chunkSize(),
            'types' => $this->excludedTypes(),
            'providers' => $this->excludedProviders(),
        ];
    }

    /** @param  list<string>  $allowed */
    private function validToggleMap(mixed $map, array $allowed): bool
    {
        if (! is_array($map)) {
            return false;
        }

        foreach ($map as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowed, true) || ! is_bool($value)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_unique(array_filter($value, 'is_string'))) : [];
    }

    private function safe(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }
}
