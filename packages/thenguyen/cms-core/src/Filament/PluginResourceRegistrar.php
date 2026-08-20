<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Generic TN CMS panel plugin that registers the Filament resources and pages
 * declared by every *active* CMS plugin (manifest "filament.resources" /
 * "filament.pages").
 *
 * This exists because Filament builds a panel's resource/page routes before
 * TN CMS boots its active plugins, so a plugin cannot register its own Filament
 * resources from its service provider in time. A `Panel::plugin()` registrant,
 * by contrast, has its register() called synchronously while the panel is being
 * built — early enough for the routes/navigation to pick the resources up.
 *
 * It is fully generic: it knows nothing about any specific plugin. Each plugin's
 * PSR-4 autoloader is ensured by the ExtensionManager before the classes are
 * resolved, so zip-installed plugins work without a Composer dump. Everything is
 * best-effort — a missing database or a broken plugin never breaks the panel.
 */
final class PluginResourceRegistrar implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'tncms-plugin-resources';
    }

    public function register(Panel $panel): void
    {
        // Core cms-core admin pages that travel with the package, so the host
        // app needs no app/Filament/.../WidgetsPage.php (v1.0.0-beta.7.1).
        $panel->pages([
            \TheNguyen\CMS\Filament\Admin\Pages\WidgetsPage::class,
        ]);

        if (! app()->bound('cms.extension')) {
            return;
        }

        $extension = app('cms.extension');

        $resources = $extension->activeFilamentResources();
        if ($resources !== []) {
            $panel->resources($resources);
        }

        $pages = $extension->activeFilamentPages();
        if ($pages !== []) {
            $panel->pages($pages);
        }
    }

    public function boot(Panel $panel): void
    {
        // Nothing to boot — registration happens in register().
    }
}
