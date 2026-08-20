<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\Language;
use TheNguyen\CMS\Services\SettingsManager;

/**
 * Seeds the two baseline languages (vi default, en) and the language.* setting
 * defaults. Idempotent: existing languages/settings are never overwritten.
 */
class CmsLanguageSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('cms_languages')) {
            return;
        }

        $languages = [
            [
                'code' => 'vi',
                'locale' => 'vi_VN',
                'name' => 'Vietnamese',
                'native_name' => 'Tiếng Việt',
                'flag' => 'vn',
                'direction' => 'ltr',
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'en',
                'locale' => 'en_US',
                'name' => 'English',
                'native_name' => 'English',
                'flag' => 'us',
                'direction' => 'ltr',
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 2,
            ],
        ];

        foreach ($languages as $attributes) {
            Language::query()->firstOrCreate(
                ['code' => $attributes['code']],
                $attributes,
            );
        }

        $this->seedSettings();
    }

    private function seedSettings(): void
    {
        if (! Schema::hasTable('cms_settings')) {
            return;
        }

        /** @var SettingsManager $settings */
        $settings = app('cms.settings');

        if (! $settings->has('language.default')) {
            $settings->set('language.default', 'vi', 'string', [
                'is_public' => true,
                'autoload' => true,
                'description' => 'Default language code',
            ]);
        }

        if (! $settings->has('language.prefix_default')) {
            $settings->set('language.prefix_default', false, 'boolean', [
                'is_public' => true,
                'autoload' => true,
                'description' => 'Prefix the default language in frontend URLs',
            ]);
        }
    }
}
