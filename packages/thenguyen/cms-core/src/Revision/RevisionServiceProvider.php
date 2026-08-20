<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use TheNguyen\CMS\Revision\Contracts\RevisionManagerInterface;
use TheNguyen\CMS\Revision\Contracts\RevisionRepositoryInterface;
use TheNguyen\CMS\Revision\Repositories\DatabaseRevisionRepository;
use TheNguyen\CMS\Revision\Support\RevisionEvents;
use TheNguyen\CMS\Support\Hooks\HookDefinition;

/**
 * Wires the reusable, locale-aware Revision Foundation into the container
 * (Phase 9.0A).
 *
 * Additive and self-contained: registered once from CmsServiceProvider. Binds
 * the relational store, the manager façade (`cms.revision`) and the transaction
 * recorder as singletons plus their public contracts, seeds config, publishes
 * it, and documents the four `cms.revision.*` action hooks. The migration ships
 * in the package migrations directory (loaded by CmsServiceProvider). The
 * platform is OFF by default (`revisions.enabled = false`) — nothing consumes it
 * and no existing behaviour changes.
 */
final class RevisionServiceProvider extends ServiceProvider
{
    private const CONFIG_PATH = __DIR__.'/../../config/revisions.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'revisions');

        $this->app->singleton('cms.revision.repository', fn () => new DatabaseRevisionRepository);
        $this->app->alias('cms.revision.repository', DatabaseRevisionRepository::class);
        $this->app->alias('cms.revision.repository', RevisionRepositoryInterface::class);

        $this->app->singleton('cms.revision', fn (Application $app) => new RevisionManager(
            $app->make(RevisionRepositoryInterface::class),
            (array) $app['config']->get('revisions', []),
        ));
        $this->app->alias('cms.revision', RevisionManager::class);
        $this->app->alias('cms.revision', RevisionManagerInterface::class);

        $this->app->singleton('cms.revision.recorder', fn (Application $app) => new RevisionRecorder(
            $app->make(RevisionManagerInterface::class),
        ));
        $this->app->alias('cms.revision.recorder', RevisionRecorder::class);
    }

    public function boot(): void
    {
        $this->publishes([self::CONFIG_PATH => config_path('revisions.php')], 'cms-revisions-config');

        $this->registerHookDefinitions();
    }

    /**
     * Document the four revision action hooks (descriptive only — they fire with
     * or without a listener). Best-effort; never breaks boot.
     */
    private function registerHookDefinitions(): void
    {
        try {
            if (! $this->app->bound('cms.hooks') || ! class_exists(HookDefinition::class)) {
                return;
            }

            $hooks = $this->app->make('cms.hooks');
            $since = '1.0.0-beta.9.0a';
            $entity = \TheNguyen\CMS\Revision\Contracts\RevisionableInterface::class;
            $revision = \TheNguyen\CMS\Revision\Models\Revision::class;
            $context = \TheNguyen\CMS\Revision\DTOs\RevisionContext::class;
            $snapshot = \TheNguyen\CMS\Revision\DTOs\RevisionSnapshot::class;

            $actions = [
                [RevisionEvents::CREATING, 'Fires before an immutable revision row is appended.', ['entity' => $entity, 'locale' => 'string', 'snapshot' => $snapshot, 'context' => $context]],
                [RevisionEvents::CREATED, 'Fires after a revision row has been appended.', ['revision' => $revision, 'entity' => $entity, 'context' => $context]],
                [RevisionEvents::PRUNING, 'Fires before locale-aware retention pruning runs.', ['entity' => $entity, 'locale' => 'string', 'keep' => 'int']],
                [RevisionEvents::PRUNED, 'Fires after retention pruning; carries the deleted count.', ['entity' => $entity, 'locale' => 'string', 'deleted' => 'int']],
            ];

            foreach ($actions as [$name, $description, $arguments]) {
                $hooks->defineAction(HookDefinition::action($name, $description, $arguments, $since, 'core', 'Revisions'));
            }
        } catch (\Throwable) {
            // Definition registration is best-effort; never break boot.
        }
    }
}
