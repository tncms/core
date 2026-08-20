<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Contracts;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;

/**
 * Persistence seam for translations, independent of any driver's read model.
 * Phase 8.0 binds the no-op {@see \TheNguyen\CMS\Translation\Repositories\NullTranslationRepository};
 * a future Database phase provides a real implementation.
 */
interface TranslationRepositoryInterface
{
    /** Load all stored locales for a key, or null when nothing is stored. */
    public function find(TranslationKey $key): ?LocalizedValue;

    public function save(TranslationKey $key, LocalizedValue $value): void;

    public function forget(TranslationKey $key): void;
}
