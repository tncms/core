<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\Support;

/**
 * The action hook names the revision platform fires. Constants (not magic
 * strings) so callers and definitions stay in sync.
 *
 *   - CREATING : before an immutable row is appended (entity, locale, snapshot, context).
 *   - CREATED  : after the row is appended (revision, entity, context).
 *   - PRUNING  : before retention pruning runs (entity, locale, keep).
 *   - PRUNED   : after pruning (entity, locale, deletedCount).
 */
final class RevisionEvents
{
    public const CREATING = 'cms.revision.creating';

    public const CREATED = 'cms.revision.created';

    public const PRUNING = 'cms.revision.pruning';

    public const PRUNED = 'cms.revision.pruned';

    private function __construct() {}
}
