<?php

declare(strict_types=1);

namespace Plugins\HelloWorld;

use Illuminate\Support\ServiceProvider;

/**
 * Example TN CMS plugin provider.
 *
 * Proves the Extension Framework boots a plugin's service provider. Routes,
 * the view namespace (hello-world::), and migrations are wired automatically by
 * the TN CMS ExtensionManager — a provider only needs to register its own
 * bindings/services. Here we bind the greeting the route + view consume, which
 * is observable proof that this provider was registered and booted.
 */
class HelloWorldServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('hello-world.greeting', static fn (): string => 'Hello World from TN CMS Plugin');
    }

    public function boot(): void
    {
        // Routes / views / migrations are loaded by the ExtensionManager.
    }
}
