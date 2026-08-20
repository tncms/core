<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Repositories;

use TheNguyen\CMS\Translation\Contracts\TranslationRepositoryInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;

/**
 * No-op repository bound by default so the {@see TranslationRepositoryInterface}
 * contract always resolves. A later Database phase replaces this binding with a
 * persistent implementation — callers never change.
 */
final class NullTranslationRepository implements TranslationRepositoryInterface
{
    public function find(TranslationKey $key): ?LocalizedValue
    {
        return null;
    }

    public function save(TranslationKey $key, LocalizedValue $value): void
    {
        // Intentionally empty — no storage in Phase 8.0.
    }

    public function forget(TranslationKey $key): void
    {
        // Intentionally empty — no storage in Phase 8.0.
    }
}
