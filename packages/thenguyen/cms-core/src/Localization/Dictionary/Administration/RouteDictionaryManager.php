<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Administration;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use TheNguyen\CMS\Localization\Dictionary\Administration\Exceptions\RouteDictionaryAdministrationException;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceInterface;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;
use TheNguyen\CMS\Localization\Dictionary\Exceptions\LocalizedRouteSegmentException;
use TheNguyen\CMS\Localization\Dictionary\Exceptions\RouteKeyException;
use TheNguyen\CMS\Localization\Dictionary\LocalizedRouteSegment;
use TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryLoader;
use TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryStoreInterface;
use TheNguyen\CMS\Localization\Dictionary\PlatformRouteDictionary;
use TheNguyen\CMS\Localization\Dictionary\ReservedRouteKeys;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;

/**
 * P6.4 — the application service that administers the persisted PROJECT Dictionary.
 *
 * It is a CONSUMER of the Localization Runtime, never part of it. It reads the persisted store
 * (the project-owned layer), validates proposed edits by reusing the frozen Runtime authorities
 * (composer + loader + RouteSegmentDictionary collision check), saves through
 * {@see RouteDictionaryStoreInterface}, and triggers a rebuild of the immutable Runtime Dictionary.
 *
 * Ownership model (Phase A/E):
 *   • Core    — a frozen {@see PlatformRouteDictionary} seed default, un-overridden. Read-only.
 *   • Plugin  — a registered plugin source segment (P6.3). Read-only; a project can never override it.
 *   • Project — a stored segment that differs from / adds to the seed. Editable.
 *
 * The admin overrides a Core localized segment ONLY by creating a Project entry — it never mutates
 * the Core seed constant or a plugin source. Nothing here mutates the Runtime in-request; edits go
 * live on the next boot (the immutable Dictionary is rebuilt, never patched in memory).
 */
final class RouteDictionaryManager
{
    public function __construct(
        private readonly RouteDictionaryStoreInterface $store,
        private readonly RouteDictionarySourceRegistry $registry,
        private readonly Application $app,
        private readonly string $storePath,
    ) {}

    /** The frozen Core seed (`key => [locale => segment]`). */
    public function coreSeed(): array
    {
        return PlatformRouteDictionary::data();
    }

    /** The full persisted base data (Core seed with any Project overrides applied). */
    public function stored(): array
    {
        $data = $this->store->exists() ? $this->store->load() : [];

        return $data !== [] ? $data : $this->coreSeed();
    }

    /**
     * (key => [locale => segment]) provided by registered plugin sources — read-only, plugin-owned.
     *
     * @return array<string, array<string, string>>
     */
    public function pluginOwned(): array
    {
        $owned = [];

        foreach ($this->registry->all() as $source) {
            foreach ($source->payload() as $key => $localeMap) {
                if (! is_array($localeMap)) {
                    continue;
                }

                foreach ($localeMap as $locale => $segment) {
                    $owned[(string) $key][(string) $locale] = (string) $segment;
                }
            }
        }

        return $owned;
    }

    /** Ownership of an effective `(key, locale)` — plugin wins over project, project over core. */
    public function ownerOf(string $key, string $locale): EntryOwnership
    {
        if (isset($this->pluginOwned()[$key][$locale])) {
            return EntryOwnership::Plugin;
        }

        $seed = $this->coreSeed();
        $stored = $this->stored();
        $seedValue = $seed[$key][$locale] ?? null;
        $storedValue = $stored[$key][$locale] ?? null;

        if ($storedValue !== null && $storedValue !== $seedValue) {
            return EntryOwnership::Project;
        }

        return EntryOwnership::Core;
    }

    /**
     * The effective Dictionary as flat administration rows — every `(key, locale)` with its
     * effective segment and owner. Plugin segments win over stored; owner reflects that.
     *
     * @return list<array{key: string, locale: string, segment: string, owner: EntryOwnership}>
     */
    public function overview(): array
    {
        $stored = $this->stored();
        $plugin = $this->pluginOwned();

        // Union of stored + plugin keys/locales; plugin value wins where both provide one.
        $keys = array_unique([...array_keys($stored), ...array_keys($plugin)]);
        sort($keys);

        $rows = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            $locales = array_unique([
                ...array_keys(is_array($stored[$key] ?? null) ? $stored[$key] : []),
                ...array_keys(is_array($plugin[$key] ?? null) ? $plugin[$key] : []),
            ]);
            sort($locales);

            foreach ($locales as $locale) {
                $locale = (string) $locale;
                $owner = $this->ownerOf($key, $locale);
                $segment = $owner === EntryOwnership::Plugin
                    ? (string) $plugin[$key][$locale]
                    : (string) ($stored[$key][$locale] ?? '');

                $rows[] = compact('key', 'locale', 'segment', 'owner');
            }
        }

        return $rows;
    }

    /**
     * The project-owned overrides only — stored entries that diverge from / add to the seed and are
     * not plugin-owned. This is the administrator-editable set.
     *
     * @return array<string, array<string, string>>
     */
    public function projectOverrides(): array
    {
        $seed = $this->coreSeed();
        $plugin = $this->pluginOwned();
        $overrides = [];

        foreach ($this->stored() as $key => $localeMap) {
            if (! is_array($localeMap)) {
                continue;
            }

            foreach ($localeMap as $locale => $segment) {
                $key = (string) $key;
                $locale = (string) $locale;

                if (isset($plugin[$key][$locale])) {
                    continue; // plugin-owned, never a project override
                }

                if ((string) $segment !== (string) ($seed[$key][$locale] ?? '')) {
                    $overrides[$key][$locale] = (string) $segment;
                }
            }
        }

        return $overrides;
    }

    /**
     * Validate proposed project overrides against the frozen Runtime authorities WITHOUT persisting.
     * Returns a list of human-readable errors ([] when valid).
     *
     * @param  array<string, array<string, string>>  $overrides
     * @return list<string>
     */
    public function validate(array $overrides): array
    {
        $errors = [];
        $seed = $this->coreSeed();
        $reserved = new ReservedRouteKeys;
        $plugin = $this->pluginOwned();

        foreach ($overrides as $key => $localeMap) {
            $key = (string) $key;

            try {
                $routeKey = RouteKey::of($key);
            } catch (RouteKeyException) {
                $errors[] = "Invalid Route Key \"{$key}\".";

                continue;
            }

            // A brand-new project key may not claim a reserved Platform identity; overriding an
            // existing (seed/core) key's localized segment is allowed.
            if (! array_key_exists($key, $seed) && $reserved->isReserved($routeKey)) {
                $errors[] = "\"{$key}\" is a reserved Platform key and cannot be created.";
            }

            if (! is_array($localeMap)) {
                $errors[] = "Entry \"{$key}\" must map locales to segments.";

                continue;
            }

            foreach ($localeMap as $locale => $segment) {
                $locale = (string) $locale;
                $segment = (string) $segment;

                if ($locale === '' || preg_match('/^[a-z]{2,8}(-[A-Za-z0-9]{1,8})*$/', $locale) !== 1) {
                    $errors[] = "Invalid locale \"{$locale}\" for \"{$key}\".";
                }

                try {
                    LocalizedRouteSegment::of($segment);
                } catch (LocalizedRouteSegmentException) {
                    $errors[] = "Invalid segment \"{$segment}\" for \"{$key}\" [{$locale}].";
                }

                if (isset($plugin[$key][$locale])) {
                    $errors[] = "\"{$key}\" [{$locale}] is owned by a plugin and cannot be overridden.";
                }
            }
        }

        // Reuse the frozen Runtime authorities for cross-key collision + composition validation.
        if ($errors === []) {
            try {
                $this->assertComposable($overrides);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Validate, persist the Project Dictionary through the store, then rebuild the Runtime Dictionary.
     * Throws (persisting nothing) when validation fails.
     *
     * @param  array<string, array<string, string>>  $overrides
     *
     * @throws RouteDictionaryAdministrationException
     */
    public function save(array $overrides, ?int $actorId = null): void
    {
        $errors = $this->validate($overrides);

        if ($errors !== []) {
            throw RouteDictionaryAdministrationException::invalid($errors);
        }

        $full = $this->applyOverrides($this->coreSeed(), $overrides);

        $this->store->save($full);
        $this->reload();
        $this->audit($overrides, $actorId);
    }

    /**
     * Apply project overrides on top of the seed (override wins, new keys/locales added).
     *
     * @param  array<string, array<string, string>>  $base
     * @param  array<string, array<string, string>>  $overrides
     * @return array<string, array<string, string>>
     */
    private function applyOverrides(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $localeMap) {
            if (! is_array($localeMap)) {
                continue;
            }

            foreach ($localeMap as $locale => $segment) {
                $base[(string) $key][(string) $locale] = (string) $segment;
            }
        }

        return $base;
    }

    /**
     * Reuse the frozen composer + loader (which build a collision-checked RouteSegmentDictionary)
     * to prove the proposed base composes with the plugin sources. Throws on any invalidity.
     *
     * @param  array<string, array<string, string>>  $overrides
     */
    private function assertComposable(array $overrides): void
    {
        $full = $this->applyOverrides($this->coreSeed(), $overrides);

        $baseSource = new class($full) implements RouteDictionarySourceInterface
        {
            /** @param array<string, array<string, string>> $data */
            public function __construct(private array $data) {}

            public function id(): string
            {
                return 'core.persistence';
            }

            public function priority(): int
            {
                return 0;
            }

            public function payload(): array
            {
                return $this->data;
            }
        };

        $composer = new RouteDictionaryComposer([$baseSource, ...$this->registry->all()]);
        (new RouteDictionaryLoader($composer))->load();
    }

    /**
     * Trigger a Runtime Dictionary rebuild: invalidate the persisted file's opcode cache and drop
     * the cached singletons so the NEXT resolve composes fresh. No in-request mutation of the
     * current immutable Dictionary — changes go live on the next boot.
     */
    private function reload(): void
    {
        if (function_exists('opcache_invalidate') && is_file($this->storePath)) {
            opcache_invalidate($this->storePath, true);
        }

        $this->app->forgetInstance('cms.localization.dictionary');
        $this->app->forgetInstance(RouteDictionaryComposer::class);
    }

    /** @param array<string, array<string, string>> $overrides */
    private function audit(array $overrides, ?int $actorId): void
    {
        Log::info('route-dictionary.project.updated', [
            'actor_id' => $actorId,
            'keys' => array_keys($overrides),
            'override_count' => array_sum(array_map(static fn ($m): int => is_array($m) ? count($m) : 0, $overrides)),
        ]);
    }
}
