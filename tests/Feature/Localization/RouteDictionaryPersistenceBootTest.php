<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use TheNguyen\CMS\Localization\Dictionary\PlatformRouteDictionary;
use TheNguyen\CMS\Localization\Dictionary\Persistence\ArrayFileRouteDictionaryStore;
use TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryStoreInterface;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;
use TheNguyen\CMS\Localization\Dictionary\RouteSegmentDictionary;
use Tests\TestCase;

/**
 * P6.1 — the Runtime dictionary is now persistence-backed, and byte-identical.
 *
 * Consumers resolve `cms.localization.dictionary` exactly as before; they never know it is loaded
 * from storage. The persisted dictionary must project identically to the frozen static seed.
 */
final class RouteDictionaryPersistenceBootTest extends TestCase
{
    public function test_dictionary_binding_is_persistence_backed_and_singleton(): void
    {
        $this->assertInstanceOf(RouteDictionaryStoreInterface::class, app(RouteDictionaryStoreInterface::class));
        $this->assertInstanceOf(ArrayFileRouteDictionaryStore::class, app(RouteDictionaryStoreInterface::class));

        $dict = app('cms.localization.dictionary');
        $this->assertInstanceOf(RouteSegmentDictionary::class, $dict);
        // Load once — the same immutable instance every resolve (no request-time reloading).
        $this->assertSame($dict, app('cms.localization.dictionary'));
        $this->assertSame($dict, app(RouteSegmentDictionary::class));
    }

    public function test_shipped_store_exists_and_matches_frozen_seed(): void
    {
        $store = app(RouteDictionaryStoreInterface::class);

        $this->assertTrue($store->exists(), 'The persistent dictionary file must ship.');
        $this->assertSame(PlatformRouteDictionary::data(), $store->load(), 'Persisted data must equal the frozen seed.');
    }

    public function test_runtime_projection_is_byte_identical_to_the_frozen_dictionary(): void
    {
        $persisted = app('cms.localization.dictionary');
        $frozen = PlatformRouteDictionary::make();

        foreach (PlatformRouteDictionary::data() as $key => $localeMap) {
            $routeKey = RouteKey::of($key);

            foreach (array_keys($localeMap) as $locale) {
                $this->assertSame(
                    $frozen->projectSegment($routeKey, $locale),
                    $persisted->projectSegment($routeKey, $locale),
                    "Forward projection for [{$key}][{$locale}] must be byte-identical.",
                );
            }

            // Default locale falls back to the canonical key on both.
            $this->assertSame(
                $frozen->projectSegment($routeKey, 'en'),
                $persisted->projectSegment($routeKey, 'en'),
            );
        }
    }
}
