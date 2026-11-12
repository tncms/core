<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CORE-SITEMAP-ADMIN-R1 — data-only, additive, idempotent.
 *
 * Seeds the three new sitemap policy settings with the values that preserve
 * the existing behaviour (enabled, nothing excluded), as autoloaded rows, so
 * the public sitemap reads them from the cached autoload map instead of
 * issuing a lookup per request. An existing row is never overwritten.
 * down() removes nothing: the rows are operator data once edited.
 */
return new class extends Migration
{
    /** key => [serialized value, type] */
    private const DEFAULTS = [
        'sitemap_enabled' => ['1', 'boolean'],
        'sitemap_excluded_types' => ['[]', 'array'],
        'sitemap_excluded_providers' => ['[]', 'array'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('cms_settings')) {
            return;
        }

        $now = now();
        $inserted = false;
        foreach (self::DEFAULTS as $key => [$value, $type]) {
            if (DB::table('cms_settings')->where('group', 'seo')->where('key', $key)->exists()) {
                continue;
            }

            // insertOrIgnore: a row written by a concurrent migrator/process after the check above is
            // kept as is (the unique group+key index decides), instead of aborting the upgrade.
            $inserted = DB::table('cms_settings')->insertOrIgnore([
                'group' => 'seo',
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'is_public' => false,
                'autoload' => true,
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]) > 0 || $inserted;
        }

        if ($inserted && app()->bound('cms.settings')) {
            // Drop the cached autoload map so the new rows are seen on the next read. Invalidate only:
            // refresh() would reload through the Eloquent settings model, which a migrator running
            // before the application boots (no connection resolver yet) cannot do.
            app('cms.settings')->clearCache();
        }
    }

    public function down(): void
    {
        // Intentionally left as is (see class docblock).
    }
};
