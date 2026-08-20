<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\Exceptions;

use RuntimeException;

/**
 * Thrown when an invariant of the append-only revision platform is violated —
 * e.g. an attempt to update or delete a persisted (immutable) revision row.
 */
final class RevisionException extends RuntimeException {}
