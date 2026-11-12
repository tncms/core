<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Directory;

/**
 * What to search. Values are taken as given here and validated/normalized by
 * {@see TaxonomyDirectory::search()} (unsupported taxonomy or locale is rejected, the query is
 * normalized and length-capped, the limit is clamped to 1..TaxonomyDirectory::MAX_LIMIT).
 */
final class TermSearchCriteria
{
    public function __construct(
        /** 'category' | 'tag'. */
        public readonly string $taxonomy,
        /** Requested locale (labels, slugs, URLs and matching). */
        public readonly string $locale,
        /** Free text; empty lists every visible term in stable order. */
        public readonly string $query = '',
        /**
         * true: only terms with a non-empty name in $locale (the strict editor policy).
         * false: every term; labels fall back requested → default locale → first translation → "Term #id".
         */
        public readonly bool $requireTranslation = true,
        public readonly int $limit = TaxonomyDirectory::DEFAULT_LIMIT,
    ) {}
}
