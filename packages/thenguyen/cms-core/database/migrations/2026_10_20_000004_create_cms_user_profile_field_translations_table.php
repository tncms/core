<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account Profile Field Engine — P1-A (CORE-ACCOUNT-PROFILE-1).
 *
 * Localized labels/help/option-labels for groups and fields. See ADR §5.4.
 * Follows Core's parent+child translation shape (cf. cms_content_translations):
 * one row per (owner_type, owner_id, locale). owner_type is 'group' | 'field'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_user_profile_field_translations', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 16);                 // 'group' | 'field'
            $table->unsignedBigInteger('owner_id');
            $table->string('locale', 20);
            $table->string('label')->nullable();
            $table->text('help')->nullable();
            $table->json('options_labels')->nullable();       // localized choice labels keyed by option value
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'locale'], 'cms_user_profile_field_tr_unique');
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_user_profile_field_translations');
    }
};
