<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account Foundation profile fields (v1.0.0-beta.7.1.15).
 *
 * Generic identity/profile columns on the shared users table. Core owns only
 * identity, profile basics, security, and preferences — never customer,
 * order, address, or membership data (those belong to plugins). Columns are
 * added only if missing so re-running against an existing install is safe.
 * Locale preferences reuse the existing admin_locale/frontend_locale/
 * editing_locale columns; no separate profile_locale is introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username', 50)->nullable()->unique()->after('name');
            }

            if (! Schema::hasColumn('users', 'avatar')) {
                $table->string('avatar')->nullable()->after('username');
            }

            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 30)->nullable()->after('avatar');
            }

            if (! Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable()->after('phone');
            }

            if (! Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone', 64)->nullable()->after('bio');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['username', 'avatar', 'phone', 'bio', 'timezone'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    // Drop the unique index on username before the column.
                    if ($column === 'username') {
                        try {
                            $table->dropUnique(['username']);
                        } catch (\Throwable) {
                            // Index may not exist on partial installs.
                        }
                    }

                    $table->dropColumn($column);
                }
            }
        });
    }
};
