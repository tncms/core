<?php

declare(strict_types=1);

namespace Plugins\SmConsumer;

use Illuminate\Support\ServiceProvider;

/** Registers the plugin's sitemap provider from the plugin's own code (call-site identity). */
final class SmConsumerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->bound('cms.sitemap_providers')) {
            $this->app->make('cms.sitemap_providers')->register(new ConsumerSitemapProvider);
        }
    }
}
