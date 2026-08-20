<?php

declare(strict_types=1);

namespace App\Search\Exceptions;

/**
 * Raised when a provider or entity type identifier collides with one already
 * registered. Rejecting duplicates is what guarantees multi-plugin isolation —
 * one plugin can never silently clobber another's search scope.
 */
final class DuplicateSearchScopeException extends SearchRegistryException
{
    public static function provider(string $key): self
    {
        return new self(sprintf(
            'A different search provider is already registered under key "%s".',
            $key,
        ));
    }

    public static function type(string $type, string $owner, string $offender): self
    {
        return new self(sprintf(
            'Search type "%s" is already owned by provider "%s"; provider "%s" cannot claim it.',
            $type,
            $owner,
            $offender,
        ));
    }
}
