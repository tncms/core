<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account Profile Field Engine — P1-A (CORE-ACCOUNT-PROFILE-1).
 *
 * Groups that bucket user profile field definitions. See
 * docs/account-profile/ADR-ACCOUNT-PROFILE-FIELDS.md §5.1. Localized labels live
 * in cms_user_profile_field_translations, not inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_user_profile_field_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();              // stable snake_case group key
            $table->string('source', 32)->default('core');     // core | plugin:<slug> | admin
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_locked')->default(false);      // plugin/core-owned: not deletable in admin
            $table->timestamps();

            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_user_profile_field_groups');
    }
};
