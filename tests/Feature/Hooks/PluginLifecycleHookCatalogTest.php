<?php

declare(strict_types=1);

namespace Tests\Feature\Hooks;

use Tests\TestCase;

/**
 * CORE-POST40-MAINTENANCE-P0-R1 / P0-09: PluginLifecycleManager fires `cms.plugin.before_activate`,
 * `after_activate`, `before_deactivate` and `after_deactivate`, but the hook catalogue (hook_definitions())
 * did not list them. Only hooks that really fire are catalogued: the upgrade/uninstall seams are never called
 * by Core today (seam phase A-2), so they must not be advertised.
 */
final class PluginLifecycleHookCatalogTest extends TestCase
{
    public function test_the_fired_plugin_lifecycle_hooks_are_catalogued(): void
    {
        $definitions = hook_definitions();

        foreach (['before_activate', 'after_activate', 'before_deactivate', 'after_deactivate'] as $event) {
            $this->assertArrayHasKey('cms.plugin.'.$event, $definitions, $event);
            $this->assertArrayHasKey('plugin', $definitions['cms.plugin.'.$event]->arguments, $event);
        }
    }

    public function test_lifecycle_hooks_core_never_fires_are_not_advertised(): void
    {
        $definitions = hook_definitions();

        foreach (['before_upgrade', 'after_upgrade', 'before_uninstall', 'after_uninstall'] as $event) {
            $this->assertArrayNotHasKey('cms.plugin.'.$event, $definitions, $event);
        }
    }
}
