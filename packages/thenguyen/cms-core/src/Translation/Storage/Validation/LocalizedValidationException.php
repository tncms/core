<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Validation;

use RuntimeException;

/**
 * Thrown when a localized value fails a hard storage validation rule (unknown
 * locale under strict mode, empty value under reject-empty, duplicate locale, or
 * an unresolvable value under require-resolvable). Callers writing to storage are
 * expected to handle this; reads never validate and never throw.
 */
final class LocalizedValidationException extends RuntimeException
{
}
