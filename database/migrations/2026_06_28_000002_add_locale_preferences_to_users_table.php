<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user locale preferences (v1.0.0-beta.7.1.10).
 *
 * `admin_locale`  — the language a logged-in user sees the admin/backend in.
 * `frontend_locale` — the language they prefer to browse the public site in.
 *
 * The two are deliberately INDEPENDENT columns so the backend UI language and
 * the frontend reading language never bleed into each other. Both are nullable
 * (no preference = fall through to the resolution chain in
 * LocalePreferenceManager) and store only an active language code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'admin_locale')) {
                $table->string('admin_locale', 20)->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'frontend_locale')) {
                $table->string('frontend_locale', 20)->nullable()->after('admin_locale');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            foreach (['admin_locale', 'frontend_locale'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
