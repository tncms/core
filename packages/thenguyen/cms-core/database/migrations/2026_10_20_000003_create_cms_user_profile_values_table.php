<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account Profile Field Engine — P1-A (CORE-ACCOUNT-PROFILE-1).
 *
 * Per-user typed values. See ADR §5.3.
 *
 * CLARIFICATION-002 (locale): `locale` is NOT NULL with default '' — the empty
 * string is the locale-neutral sentinel. A nullable locale would let SQL's
 * distinct-NULL semantics admit duplicate (user, field, neutral) rows on both
 * MySQL and SQLite; the sentinel makes UNIQUE(user_id, field_id, locale)
 * enforceable identically on both engines.
 *
 * CLARIFICATION-003-REVISED (P1-B — user ownership & deletion cleanup): this is
 * a privacy-sensitive, user-OWNED values table, unlike the cms_role_user pivot.
 * It therefore carries a real foreign key `user_id` → `users(id)` with
 * ON DELETE CASCADE, matching the host PK type (unsigned bigint) and following
 * the existing host-users FK precedent in the ecommerce plugin. The host User
 * model hard-deletes (no SoftDeletes), so the engine cascade is the complete,
 * query-free cleanup — no application pre-delete handler is registered, which
 * preserves UserDeletionManager's lean-path / zero-default-handler invariants.
 * This migration was never applied to any persistent, demo, or live database in
 * the P1-A lineage (SQLite :memory: tests only), so adding the FK to the create
 * migration — rather than an ALTER (which SQLite cannot do for FKs) — is safe.
 * `updated_by` stays FK-free (it is provenance metadata, nullable). field_id
 * keeps its Core-table cascade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_user_profile_values', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');                   // host users PK type (unsigned bigint)
            $table->foreignId('field_id')->constrained('cms_user_profile_fields')->cascadeOnDelete();
            $table->string('field_key', 150);                        // denormalized stable key for fast reads
            $table->string('locale', 20)->default('');               // '' = locale-neutral (CLARIFICATION-002)
            $table->json('value')->nullable();                       // normalized typed value
            $table->string('value_index', 191)->nullable();          // lowercased scalar projection for indexed filters
            $table->unsignedBigInteger('updated_by')->nullable();    // provenance actor id (host-owned; no FK)
            $table->timestamps();

            $table->unique(['user_id', 'field_id', 'locale'], 'cms_user_profile_values_unique');
            $table->index(['field_key', 'value_index']);

            // Privacy-sensitive owned values: cascade when the owning user is
            // hard-deleted (CLARIFICATION-003-REVISED). Indexes the column too.
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_user_profile_values');
    }
};
