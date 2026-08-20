<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Database\Seeders;

use Illuminate\Database\Seeder;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\Setting;
use TheNguyen\CMS\Services\SettingsManager;

class CmsSettingsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var SettingsManager $settings */
        $settings = app('cms.settings');

        $defaults = [
            ['general.site_name',        'TN CMS',                                     'string',  'Public site name'],
            ['general.site_tagline',     'Laravel + Filament CMS',                     'string',  'Public site tagline'],
            ['general.site_description', 'Laravel + Filament CMS foundation',          'text',    'Public site description'],
            ['general.admin_email',      'admin@example.com',                          'string',  'Primary admin email'],
            ['general.timezone',         (string) config('app.timezone', 'UTC'),       'string',  'Site timezone'],
            ['general.date_format',      'd/m/Y',                                      'string',  'Date display format'],
            ['general.time_format',      'H:i',                                        'string',  'Time display format'],
            ['general.favicon',          '',                                           'string',  'Favicon URL'],

            // Note: reading.homepage_display is intentionally NOT seeded so the
            // default homepage keeps its existing behaviour (the seeded
            // "trang-chu" page). Choosing latest_posts / static_page is opt-in
            // via Settings → Reading.
            ['reading.homepage_page_id', '0',                                          'integer', 'Static homepage page id'],
            ['reading.posts_page_id',    '0',                                          'integer', 'Blog posts page id'],
            ['reading.posts_per_page',   '10',                                         'integer', 'Posts per page'],
            ['reading.feed_items_count', '10',                                         'integer', 'Feed items count'],
            ['reading.feed_content_mode', 'excerpt',                                   'string',  'Feed content mode'],

            ['writing.default_post_status',    'draft',                               'string',  'Default new post status'],
            ['writing.default_comment_status', 'open',                                'string',  'Default new comment status'],
            ['writing.default_category_id',    '0',                                   'integer', 'Default post category id'],
            ['writing.default_language',       'vi',                                  'string',  'Default authoring language'],
            ['writing.default_post_format',    'standard',                            'string',  'Default post format'],

            ['media.organize_uploads_by_date', '1',                                   'boolean', 'Organize uploads by year/month'],
            ['media.max_upload_size_mb',       '8',                                   'integer', 'Max upload size (MB)'],
            ['media.auto_alt_from_filename',   '1',                                   'boolean', 'Auto alt from filename'],
            ['media.auto_title_from_filename', '0',                                   'boolean', 'Auto title from filename'],
            ['media.sizes.thumbnail.width',    '150',                                 'integer', 'Thumbnail width'],
            ['media.sizes.thumbnail.height',   '150',                                 'integer', 'Thumbnail height'],
            ['media.sizes.thumbnail.crop',     '1',                                   'boolean', 'Crop thumbnail'],
            ['media.sizes.medium.width',       '768',                                 'integer', 'Medium max width'],
            ['media.sizes.medium.height',      '768',                                 'integer', 'Medium max height'],
            ['media.sizes.large.width',        '1536',                                'integer', 'Large max width'],
            ['media.sizes.large.height',       '1536',                                'integer', 'Large max height'],
            ['media.custom_sizes',             [],                                    'array',   'Custom image sizes'],

            ['seo.default_title',        'TN CMS',                                     'string',  'Default SEO title (legacy)'],
            ['seo.default_description',  'A site powered by TN CMS',                   'text',    'Default SEO description (legacy)'],
            ['seo.noindex_site',         '0',                                          'boolean', 'Discourage search engines'],
            ['seo.title_separator',      '|',                                          'string',  'SEO title separator'],
            ['seo.default_meta_title',   '',                                           'string',  'Default meta title'],
            ['seo.default_meta_description', '',                                       'text',    'Default meta description'],
            ['seo.default_og_image',     '',                                           'string',  'Default Open Graph image URL'],
            ['seo.robots_default',       'index,follow',                               'string',  'Default robots directive'],

            ['permalink.post_base',      'blog',                                       'string',  'Post URL base'],
            ['permalink.category_base',  'category',                                   'string',  'Category URL base'],
            ['permalink.tag_base',       'tag',                                        'string',  'Tag URL base'],

            ['admin.brand_name',         'TN CMS',                                     'string',  'Admin panel brand name'],

            ['system.deployment_mode',   (string) config('cms.deployment.mode', 'public_root'), 'string', 'Deployment layout mode'],
            ['theme.active',             (string) config('cms.theme.active', 'default'),       'string', 'Active theme slug'],
        ];

        foreach ($defaults as [$key, $value, $type, $description]) {
            [$group, $name] = $this->parseKey($key);

            $exists = Setting::query()
                ->where('group', $group)
                ->where('key', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            $settings->set($key, $value, $type, [
                'is_public' => true,
                'autoload' => true,
                'description' => $description,
            ]);
        }

        $this->seedMaintenance($settings);
        $this->seedFrontendAuth($settings);

        // Localized Settings Framework (v1.0.0-beta.6.2). Copy each localizable
        // global value into the CMS default locale's translation row so the
        // default language shows real values out of the box. Global values stay
        // in cms_settings as the permanent fallback. Idempotent.
        $settings->backfillLocalizedDefaults(app('cms.language')->defaultCode());
    }

    /**
     * Maintenance Mode Core (v1.0.0-beta.4). Kept private (is_public = false)
     * because allowed IPs / excluded paths are internal operator config; still
     * autoloaded so the frontend gate reads them from cache. Idempotent.
     */
    private function seedMaintenance(SettingsManager $settings): void
    {
        $defaults = [
            ['maintenance.enabled',                '0',                                                     'boolean', 'Maintenance mode enabled'],
            ['maintenance.mode',                   'theme',                                                 'string',  'Display mode: theme | page'],
            ['maintenance.page_id',                '0',                                                     'integer', 'Custom maintenance page id'],
            ['maintenance.title',                  "We'll be back soon",                                    'string',  'Maintenance title'],
            ['maintenance.message',                'Our website is currently undergoing scheduled maintenance. Please check back later.', 'text', 'Maintenance message'],
            ['maintenance.status_code',            '503',                                                   'integer', 'HTTP status code (503 | 200)'],
            ['maintenance.retry_after_minutes',    '30',                                                    'integer', 'Retry-After minutes (503 only)'],
            ['maintenance.allow_admin_bypass',     '1',                                                     'boolean', 'Allow system.maintenance.bypass holders to view the site'],
            ['maintenance.allow_logged_in_bypass', '0',                                                     'boolean', 'Allow any authenticated user to view the site'],
            ['maintenance.allowed_ips',            [],                                                      'array',   'IPs that bypass maintenance'],
            ['maintenance.exclude_paths',          ['admin', 'login', 'livewire', 'robots.txt', 'sitemap.xml', 'cms-health'], 'array', 'Paths that remain accessible'],
        ];

        foreach ($defaults as [$key, $value, $type, $description]) {
            [$group, $name] = $this->parseKey($key);

            $exists = Setting::query()
                ->where('group', $group)
                ->where('key', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            $settings->set($key, $value, $type, [
                'is_public' => false,
                'autoload' => true,
                'description' => $description,
            ]);
        }
    }

    /**
     * Frontend Authentication settings (v1.0.0-beta.7.1.14). Private, autoloaded
     * config for registration, session lifetimes, and the device/session
     * invalidation policy. default_role_id resolves to the safe subscriber role.
     */
    private function seedFrontendAuth(SettingsManager $settings): void
    {
        $subscriberId = (int) (Role::query()->where('slug', 'subscriber')->value('id') ?? 0);

        $defaults = [
            ['auth.registration_enabled',              '1',                    'boolean', 'Allow frontend self-registration'],
            ['auth.default_role_id',                   (string) $subscriberId, 'integer', 'Role id assigned to new registrations'],
            ['auth.email_verification_required',       '0',                    'boolean', 'Require email verification for protected routes'],
            ['auth.auto_login_after_registration',     '1',                    'boolean', 'Log users in immediately after registration'],
            ['auth.frontend_session_lifetime_minutes', '1440',                 'integer', 'Frontend session lifetime (minutes)'],
            ['auth.frontend_remember_lifetime_minutes', '10080',                'integer', 'Frontend remember-me lifetime (minutes)'],
            ['auth.admin_session_lifetime_minutes',    '720',                  'integer', 'Admin session lifetime (minutes)'],
            ['auth.admin_remember_lifetime_minutes',   '43200',                'integer', 'Admin remember-me lifetime (minutes)'],
            ['auth.rotate_session_on_device_change',   '1',                    'boolean', 'Invalidate old sessions on device change'],
            ['auth.force_logout_on_password_change',   '1',                    'boolean', 'Invalidate other sessions on password change'],
            ['auth.single_session_per_user',           '0',                    'boolean', 'Only one active session per user'],
            ['auth.idle_timeout_minutes',              '0',                    'integer', 'Idle timeout in minutes (0 = disabled)'],
        ];

        foreach ($defaults as [$key, $value, $type, $description]) {
            [$group, $name] = $this->parseKey($key);

            $exists = Setting::query()
                ->where('group', $group)
                ->where('key', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            $settings->set($key, $value, $type, [
                'is_public' => false,
                'autoload' => true,
                'description' => $description,
            ]);
        }
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function parseKey(string $key): array
    {
        if (! str_contains($key, '.')) {
            return [null, $key];
        }

        [$group, $name] = explode('.', $key, 2);

        return [$group, $name];
    }
}
