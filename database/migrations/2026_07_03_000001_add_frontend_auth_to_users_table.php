<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frontend Identity & Authentication Foundation (v1.0.0-beta.7.1.14).
 *
 * Adds frontend session/device tracking columns to the shared users table.
 * There is no separate customers table: one users table is shared across
 * frontend and admin, with roles deciding capability. Columns are added only
 * if missing so re-running against an existing install is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'frontend_last_login_at')) {
                $table->timestamp('frontend_last_login_at')->nullable()->after('email_verified_at');
            }

            if (! Schema::hasColumn('users', 'frontend_last_login_ip')) {
                $table->string('frontend_last_login_ip', 45)->nullable()->after('frontend_last_login_at');
            }

            if (! Schema::hasColumn('users', 'frontend_last_user_agent_hash')) {
                $table->string('frontend_last_user_agent_hash', 64)->nullable()->after('frontend_last_login_ip');
            }

            if (! Schema::hasColumn('users', 'frontend_session_version')) {
                $table->unsignedInteger('frontend_session_version')->default(1)->after('frontend_last_user_agent_hash');
            }

            if (! Schema::hasColumn('users', 'remember_token_rotated_at')) {
                $table->timestamp('remember_token_rotated_at')->nullable()->after('frontend_session_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach ([
                'frontend_last_login_at',
                'frontend_last_login_ip',
                'frontend_last_user_agent_hash',
                'frontend_session_version',
                'remember_token_rotated_at',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
