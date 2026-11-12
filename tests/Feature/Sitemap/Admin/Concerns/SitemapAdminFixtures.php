<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap\Admin\Concerns;

use App\Models\User;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/** CORE-SITEMAP-ADMIN-R1: users holding exactly the sitemap grants a test needs. */
trait SitemapAdminFixtures
{
    /**
     * {@see \Tests\Feature\Sitemap\Concerns\SitemapFixtures::smRoutes()} rebuilds
     * the router from Core + fixture routes only; the admin page also needs
     * the Filament panel and Livewire endpoints, which are re-added as-is.
     */
    protected function smRoutesWithAdmin(string ...$slugs): void
    {
        $admin = array_filter(
            app('router')->getRoutes()->getRoutes(),
            static fn ($route): bool => str_starts_with((string) $route->getName(), 'filament.')
                || str_starts_with((string) $route->getName(), 'livewire.')
                || str_starts_with($route->uri(), 'livewire'),
        );

        $this->smRoutes(...$slugs);

        $routes = app('router')->getRoutes();
        foreach ($admin as $route) {
            $routes->add($route);
        }
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();
    }

    protected function seedRbac(): void
    {
        if (! Role::query()->where('slug', 'super-admin')->exists()) {
            $this->seed(CmsRolePermissionSeeder::class);
        }
    }

    protected function userWithRole(string $role): User
    {
        $this->seedRbac();
        $user = User::factory()->create();
        Role::query()->where('slug', $role)->firstOrFail()->users()->syncWithoutDetaching([$user->id]);
        app('cms.permission')->flushResolutionCache();

        return $user;
    }

    /** A custom role with admin.access plus the given sitemap permissions. */
    protected function userWith(string ...$permissions): User
    {
        $this->seedRbac();
        $role = Role::query()->create([
            'slug' => 'sm-role-'.substr(md5(implode(',', $permissions).microtime()), 0, 8),
            'name' => 'Sitemap test role',
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', ['admin.access', ...$permissions])->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $role->users()->syncWithoutDetaching([$user->id]);
        app('cms.permission')->flushResolutionCache();

        return $user;
    }
}
