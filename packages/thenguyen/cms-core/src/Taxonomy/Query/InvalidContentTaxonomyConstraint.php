<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Query;

use InvalidArgumentException;

/**
 * A content-taxonomy constraint request the caller must not retry unchanged: an unsupported taxonomy, match mode
 * or related mode, an oversized term selection, or a builder that is not a plain `Content` query over
 * `cms_contents`. The message is a stable machine reason (see the REASON_* constants), never user input and never SQL.
 *
 * @api
 */
final class InvalidContentTaxonomyConstraint extends InvalidArgumentException
{
    public const REASON_TAXONOMY = 'unsupported_taxonomy';

    public const REASON_MATCH = 'unsupported_match';

    public const REASON_MODE = 'unsupported_related_mode';

    public const REASON_TOO_MANY_TERMS = 'too_many_terms';

    public const REASON_QUERY = 'incompatible_query';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
