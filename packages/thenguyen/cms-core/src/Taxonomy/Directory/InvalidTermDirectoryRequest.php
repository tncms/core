<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Directory;

use InvalidArgumentException;

/**
 * A taxonomy directory request the caller must not retry unchanged: an unsupported taxonomy, a
 * disabled locale, a malformed, tampered or foreign cursor, or an oversized batch. The message is
 * a stable machine reason (see the REASON_* constants), never user input and never SQL.
 */
final class InvalidTermDirectoryRequest extends InvalidArgumentException
{
    public const REASON_TAXONOMY = 'unsupported_taxonomy';

    public const REASON_LOCALE = 'unsupported_locale';

    public const REASON_CURSOR = 'invalid_cursor';

    public const REASON_CURSOR_SCOPE = 'cursor_scope_mismatch';

    public const REASON_BATCH = 'batch_too_large';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
