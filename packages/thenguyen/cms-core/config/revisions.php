<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content Revision Foundation (Phase 9.0A) — enabled by default (Phase 9.2I)
|--------------------------------------------------------------------------
| The reusable, locale-aware revision platform. Phase 9.2I enables it as the
| production default for the certified modules (Posts, Pages, taxonomy terms)
| now that their adapter runtime is stable. Recording is best-effort and fully
| fault-isolated — the enclosing content/term write NEVER fails because of a
| revision write (ContentManager and TaxonomyManager both wrap the recorder in
| a Throwable-safe boundary). Rollback is configuration only: set
| `CMS_REVISIONS_ENABLED=false` (or the per-entity flags) — no data cleanup, the
| existing history is simply left untouched.
*/

return [
    // Master switch. false = record nothing (pre-9.2I behaviour, zero table growth).
    // Rollback: CMS_REVISIONS_ENABLED=false.
    'enabled' => (bool) env('CMS_REVISIONS_ENABLED', true),

    // Per-entity opt-in, checked by the write path that records (this sits ABOVE
    // the master switch — BOTH must be true to record). Phase 9.0F wires Posts,
    // Phase 9.1F wires Pages, and Phase 9.2G wires taxonomy terms (ONE flag for
    // EVERY taxonomy — category/tag/brand/…); other entities stay off until their
    // own phase wires them. Phase 9.2I enables the three certified entities by
    // default (rollback = set the matching env var to false). Taxonomy revisions
    // capture LOCALIZED content only (never hierarchy/parent_id/ordering/count).
    'entities' => [
        'posts' => (bool) env('CMS_REVISIONS_POSTS', true),
        'pages' => (bool) env('CMS_REVISIONS_PAGES', true),
        'taxonomy' => (bool) env('CMS_REVISIONS_TAXONOMY', true),
    ],

    // Keep the newest N revisions per (entity, locale). 0 = unlimited (never prune).
    'max_per_locale' => (int) env('CMS_REVISIONS_MAX_PER_LOCALE', 5),

    // Retention strategy applied after a successful write:
    //   'keep_latest' — prune everything older than the newest `max_per_locale`.
    //   'none'        — never prune (retain full history regardless of the cap).
    'prune_strategy' => (string) env('CMS_REVISIONS_PRUNE_STRATEGY', 'keep_latest'),

    // When true, RevisionManager::diagnostics() reports per-locale counts for an
    // entity (used by the future health surface). Purely observational.
    'diagnostics' => (bool) env('CMS_REVISIONS_DIAGNOSTICS', true),
];
