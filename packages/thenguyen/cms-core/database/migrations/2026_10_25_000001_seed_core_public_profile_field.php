<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public-profile consent flag (CORE-EXTENSION-TRUST S3, owner decision Q1-B).
 *
 * Data rows only — no schema change. Seeds the locked Core group `core` and the
 * boolean field `core.public_profile` (owner-visible, owner-editable, default
 * false) in the existing profile-field tables. The public profile provider
 * projects a user only while this flag is exactly true; it shows up on the
 * existing /account/profile form, so every user opts in (and out) themself.
 * Idempotent by key; never touches stored consent values on re-run.
 */
return new class extends Migration
{
    private const GROUP = 'core';

    private const FIELD = 'core.public_profile';

    /** @var array<string, string> locale => label ('' is the neutral fallback) */
    private const LABELS = [
        '' => 'Show my public profile',
        'en' => 'Show my public profile',
        'vi' => 'Hiển thị hồ sơ công khai của tôi',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('cms_user_profile_field_groups')
            || ! Schema::hasTable('cms_user_profile_fields')
            || ! Schema::hasTable('cms_user_profile_field_translations')) {
            return;
        }

        DB::transaction(function (): void {
            $now = now();

            if (! DB::table('cms_user_profile_field_groups')->where('key', self::GROUP)->exists()) {
                DB::table('cms_user_profile_field_groups')->insert([
                    'key' => self::GROUP, 'source' => 'core', 'sort_order' => 0,
                    'is_active' => true, 'is_locked' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $groupId = (int) DB::table('cms_user_profile_field_groups')->where('key', self::GROUP)->value('id');

            if (! DB::table('cms_user_profile_fields')->where('key', self::FIELD)->exists()) {
                DB::table('cms_user_profile_fields')->insert([
                    'key' => self::FIELD, 'group_id' => $groupId, 'type' => 'boolean',
                    'options' => null, 'validation' => null, 'default_value' => 'false',
                    'visibility' => 'owner', 'editability' => 'owner', 'is_encrypted' => false,
                    'source' => 'core', 'sort_order' => 0, 'is_active' => true, 'is_locked' => true,
                    'schema_revision' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $fieldId = (int) DB::table('cms_user_profile_fields')->where('key', self::FIELD)->value('id');

            foreach (self::LABELS as $locale => $label) {
                $exists = DB::table('cms_user_profile_field_translations')
                    ->where('owner_type', 'field')->where('owner_id', $fieldId)->where('locale', $locale)->exists();

                if (! $exists) {
                    DB::table('cms_user_profile_field_translations')->insert([
                        'owner_type' => 'field', 'owner_id' => $fieldId, 'locale' => $locale,
                        'label' => $label, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    /** Rollback removes the flag AND every user's stored consent (documented). */
    public function down(): void
    {
        if (! Schema::hasTable('cms_user_profile_fields')) {
            return;
        }

        DB::transaction(function (): void {
            $fieldId = DB::table('cms_user_profile_fields')->where('key', self::FIELD)->where('source', 'core')->value('id');

            if ($fieldId !== null) {
                DB::table('cms_user_profile_values')->where('field_id', $fieldId)->delete();
                DB::table('cms_user_profile_field_translations')->where('owner_type', 'field')->where('owner_id', $fieldId)->delete();
                DB::table('cms_user_profile_fields')->where('id', $fieldId)->delete();
            }

            $groupId = DB::table('cms_user_profile_field_groups')->where('key', self::GROUP)->where('source', 'core')->value('id');

            if ($groupId !== null && ! DB::table('cms_user_profile_fields')->where('group_id', $groupId)->exists()) {
                DB::table('cms_user_profile_field_groups')->where('id', $groupId)->delete();
            }
        });
    }
};
