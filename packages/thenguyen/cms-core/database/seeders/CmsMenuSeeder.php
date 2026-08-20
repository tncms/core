<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Database\Seeders;

use Illuminate\Database\Seeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Menu;
use TheNguyen\CMS\Services\MenuManager;

class CmsMenuSeeder extends Seeder
{
    public function run(): void
    {
        /** @var MenuManager $menus */
        $menus = app('cms.menu');

        $header = $this->ensureMenu($menus, [
            'slug' => 'header-menu',
            'location' => 'header',
            'status' => 'active',
            'name' => 'Header Menu',
        ]);

        $this->ensureMenu($menus, [
            'slug' => 'footer-menu',
            'location' => 'footer',
            'status' => 'active',
            'name' => 'Footer Menu',
        ]);

        // Attach sample content items to the header menu when available.
        $samplePage = Content::query()->where('type', 'page')->orderBy('id')->first();
        if ($samplePage !== null) {
            $this->ensureReferenceItem($menus, $header, 'page', $samplePage->id, $samplePage->translatedTitle('vi'));
        }

        $samplePost = Content::query()->where('type', 'post')->orderBy('id')->first();
        if ($samplePost !== null) {
            $this->ensureReferenceItem($menus, $header, 'post', $samplePost->id, $samplePost->translatedTitle('vi'));
        }
    }

    /**
     * @param  array{slug: string, location: string, status: string, name: string}  $data
     */
    private function ensureMenu(MenuManager $menus, array $data): Menu
    {
        $existing = Menu::query()->where('slug', $data['slug'])->first();

        if ($existing !== null) {
            return $existing;
        }

        return $menus->createMenu([
            'locale' => 'vi',
            'name' => $data['name'],
            'slug' => $data['slug'],
            'location' => $data['location'],
            'status' => $data['status'],
            'is_system' => true,
        ]);
    }

    /**
     * Add a page/post reference item once per (menu, type, reference_id).
     */
    private function ensureReferenceItem(
        MenuManager $menus,
        Menu $menu,
        string $type,
        int $referenceId,
        string $title,
    ): void {
        $exists = $menu->items()
            ->where('type', $type)
            ->where('reference_type', 'content')
            ->where('reference_id', $referenceId)
            ->exists();

        if ($exists) {
            return;
        }

        $menus->createItem($menu, [
            'locale' => 'vi',
            'title' => $title,
            'type' => $type,
            'reference_id' => $referenceId,
            'target' => '_self',
            'is_active' => true,
            'sort_order' => $menu->items()->count(),
        ]);
    }
}
