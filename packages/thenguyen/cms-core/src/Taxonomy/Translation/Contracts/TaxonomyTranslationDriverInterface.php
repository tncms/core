<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Translation\Contracts;

use TheNguyen\CMS\Translation\Contracts\RelationalTranslationDriverInterface;

/**
 * The driver contract for taxonomy term translations (Phase 9.2B).
 *
 * It is a REFINEMENT of the platform's {@see RelationalTranslationDriverInterface}
 * — it adds no methods; it exists so that a taxonomy driver is a recognizable,
 * bindable type and is guaranteed to operate over the canonical taxonomy field set
 * ({@see \TheNguyen\CMS\Taxonomy\Translation\TaxonomyTranslationFields}).
 *
 * The concrete driver (Phase 9.2C, NOT this phase) is
 * `TermTranslationDriver extends AbstractRelationalTranslationDriver implements
 * TaxonomyTranslationDriverInterface`, bound over `cms_term_translations`
 * (foreign key `term_id`, locale column `locale`, fields name/slug/description/
 * meta_title/meta_description). Because ALL taxonomies share `cms_term_translations`,
 * ONE driver serves every taxonomy — core and plugin — with no per-taxonomy code.
 *
 * The certified `content_relational` driver is deliberately NOT reused: terms use a
 * different table and different field names (name≠title, single description, no
 * meta_keywords). This interface is exactly the platform's designed extension seam
 * — it requires NO Translation Platform modification.
 *
 * STABLE SDK contract.
 *
 * @since 1.0
 *
 * @stable
 */
interface TaxonomyTranslationDriverInterface extends RelationalTranslationDriverInterface
{
    // Marker refinement — no additional members. The base contract's read/write
    // surface (read/readAllLocales/batchRead/exists/create/update/batchWrite/
    // delete/diagnostics + version/supports/isWritable/capabilities) is the full
    // driver API; taxonomy drivers simply guarantee the taxonomy field vocabulary.
}
