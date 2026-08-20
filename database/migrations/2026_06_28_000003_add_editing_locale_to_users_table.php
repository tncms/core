<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user content editing locale (v1.0.0-beta.7.1.10.2).
 *
 * `editing_locale` — the language a logged-in user was last EDITING content
 * translations in (posts, pages, terms, menus, widgets, localized settings).
 * It is the third, independent member of the locale trio alongside
 * `admin_locale` (the admin INTERFACE language) and `frontend_locale` (the
 * public reading language) added in beta.7.1.10. The three never bleed into
 * each other. Nullable (no preference = fall through to the resolution chain in
 * LocalePreferenceManager) and stores only an active language code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'editing_locale')) {
                $after = Schema::hasColumn('users', 'frontend_locale') ? 'frontend_locale' : 'email';
                $table->string('editing_locale', 20)->nullable()->after($after);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'editing_locale')) {
                $table->dropColumn('editing_locale');
            }
        });
    }
};
