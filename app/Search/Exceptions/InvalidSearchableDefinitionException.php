<?php

declare(strict_types=1);

namespace App\Search\Exceptions;

/**
 * Raised when a provider or one of its entity definitions is malformed. Every
 * definition must fully state its identity, searchable fields, model, and
 * visibility guarantees before it can join the platform.
 */
final class InvalidSearchableDefinitionException extends SearchRegistryException
{
    public static function badProviderKey(string $key): self
    {
        return new self(sprintf(
            'Invalid search provider key "%s"; expected a non-empty ^[a-z][a-z0-9_-]*$ slug.',
            $key,
        ));
    }

    public static function emptyProvider(string $key): self
    {
        return new self(sprintf(
            'Search provider "%s" declares no searchable entity definitions.',
            $key,
        ));
    }

    public static function badType(string $type): self
    {
        return new self(sprintf(
            'Invalid searchable type "%s"; expected a non-empty ^[a-z][a-z0-9_-]*$ slug.',
            $type,
        ));
    }

    public static function missingLabel(string $type): self
    {
        return new self(sprintf('Searchable type "%s" must declare a non-empty label.', $type));
    }

    public static function noFields(string $type): self
    {
        return new self(sprintf('Searchable type "%s" must declare at least one searchable field.', $type));
    }

    public static function noVisibilityRules(string $type): self
    {
        return new self(sprintf(
            'Searchable type "%s" must declare its visibility rules (e.g. published, public, non-deleted).',
            $type,
        ));
    }

    public static function badModel(string $type, string $model): self
    {
        return new self(sprintf(
            'Searchable type "%s" resolves to model "%s" which does not exist.',
            $type,
            $model,
        ));
    }
}
