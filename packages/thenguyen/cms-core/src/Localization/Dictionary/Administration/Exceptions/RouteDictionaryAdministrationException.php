<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Administration\Exceptions;

use RuntimeException;

/**
 * P6.4 — raised when proposed Project Dictionary edits fail validation, BEFORE anything is
 * persisted. Carries the full list of human-readable validation errors so the admin UI can show
 * them all at once. Nothing is written when this is thrown (fail-closed).
 */
final class RouteDictionaryAdministrationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors === [] ? 'Invalid Project Dictionary edit.' : implode(' ', $errors));
    }

    /** @param list<string> $errors */
    public static function invalid(array $errors): self
    {
        return new self($errors);
    }
}
