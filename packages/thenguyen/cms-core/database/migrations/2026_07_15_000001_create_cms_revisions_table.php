<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable, locale-aware content revision foundation (Phase 9.0A).
 *
 * One polymorphic, append-only history table for every localized entity
 * (posts, pages, terms, menus, products, …). A single row captures ONE locale's
 * localized field map at a point in time:
 *
 *   - entity_type      : the Revisionable type, e.g. 'content' | 'term'.
 *   - entity_id        : the owning record's stable PK (string for polymorphic
 *                        reuse — integer ids are stored as their string form).
 *   - locale           : BCP-47-ish code, e.g. 'vi', 'en'.
 *   - revision_number  : monotonic PER (entity_type, entity_id, locale). 'vi' and
 *                        'en' number independently.
 *   - snapshot         : the localized field map (JSON; cast 'array').
 *   - author_id        : FK-less, nullable — system/import writes have no author.
 *   - source           : RevisionSource ('admin'|'api'|'import'|'restore'|'cli'|'system').
 *   - reason           : optional note, e.g. "Restored from #7".
 *   - checksum         : sha256 of the canonical snapshot — O(1) no-op detection.
 *   - created_at       : write time. There is NO updated_at: rows are immutable.
 *
 * The unique (entity_type, entity_id, locale, revision_number) index is the
 * numbering-integrity guard. Additive and standalone — it references no other
 * table and no module writes to it yet (Phase 9.0A is the platform layer only;
 * the recorder is not wired into ContentManager). Disabled by default via
 * config('revisions.enabled').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 64);
            $table->string('entity_id', 191);
            $table->string('locale', 20);
            $table->unsignedInteger('revision_number');
            $table->json('snapshot')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('source', 20)->default('system');
            $table->text('reason')->nullable();
            $table->string('checksum', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(
                ['entity_type', 'entity_id', 'locale', 'revision_number'],
                'cms_revisions_identity_unique',
            );
            $table->index(['entity_type', 'entity_id', 'locale'], 'cms_revisions_history_index');
            $table->index('checksum', 'cms_revisions_checksum_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_revisions');
    }
};
