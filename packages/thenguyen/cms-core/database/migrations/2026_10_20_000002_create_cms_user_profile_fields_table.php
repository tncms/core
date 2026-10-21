<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account Profile Field Engine — P1-A (CORE-ACCOUNT-PROFILE-1).
 *
 * Field definitions. See ADR §5.2. Typed, declaratively validated (no PHP),
 * privacy-first (visibility defaults to 'owner'). group_id FK restrictOnDelete:
 * a group with fields cannot be dropped out from under them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_user_profile_fields', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 150)->unique();                 // stable namespaced key e.g. professional.job_title
            $table->foreignId('group_id')->constrained('cms_user_profile_field_groups')->restrictOnDelete();
            $table->string('type', 32);                           // text|textarea|number|date|datetime|select|checkbox|radio|boolean|url|email|media
            $table->json('options')->nullable();                  // choices / schemes / min-max — structured, no PHP
            $table->json('validation')->nullable();               // declarative rule bag — no raw rules/closures
            $table->json('default_value')->nullable();
            $table->string('visibility', 16)->default('owner');   // public|authenticated|owner|admin
            $table->string('editability', 16)->default('owner');  // owner|admin|system|immutable
            $table->boolean('is_encrypted')->default(false);
            $table->string('source', 32)->default('core');        // core | plugin:<slug> | admin
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);          // inactive = hidden everywhere, values retained
            $table->boolean('is_locked')->default(false);
            $table->unsignedInteger('schema_revision')->default(1);
            $table->timestamps();

            $table->index(['group_id', 'sort_order']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_user_profile_fields');
    }
};
