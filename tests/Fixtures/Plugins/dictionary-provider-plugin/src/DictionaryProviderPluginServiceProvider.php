<?php

declare(strict_types=1);

namespace Plugins\DictionaryProviderPlugin;

use Illuminate\Support\ServiceProvider;
use TheNguyen\CMS\Localization\Dictionary\Composition\PluginRouteDictionarySource;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;

/**
 * TEST FIXTURE (not a production plugin). Discovered only when a test points
 * `cms.paths.plugins` at tests/Fixtures/Plugins.
 *
 * It registers one build-time Route Dictionary source so the real plugin lifecycle can be
 * integration-tested end to end: ExtensionManager two-pass boot → provider register() →
 * RouteDictionarySourceRegistry → Composer → Loader → immutable RouteSegmentDictionary.
 */
final class DictionaryProviderPluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->make(RouteDictionarySourceRegistry::class)->register(
            new PluginRouteDictionarySource('dictionary-provider-plugin', [
                'faq' => ['vi' => 'cau-hoi-thuong-gap', 'de' => 'faq'],
            ]),
        );
    }

    public function boot(): void
    {
        // No routes, views, migrations, or assets — a Dictionary source only.
    }
}
