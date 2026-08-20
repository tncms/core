<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Drivers;

use TheNguyen\CMS\Translation\Contracts\TranslationDriverInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;

/**
 * The default, always-empty driver. It makes the engine fully functional out of
 * the box (nothing to configure) while holding no translations — real drivers
 * (Database/JSON/YAML/Remote/AI) arrive in later phases behind the same contract.
 */
final class NullTranslationDriver implements TranslationDriverInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function get(TranslationKey $key, string $locale, TranslationContext $context): ?string
    {
        return null;
    }

    public function has(TranslationKey $key, string $locale, TranslationContext $context): bool
    {
        return false;
    }

    public function all(TranslationKey $key, TranslationContext $context): LocalizedValue
    {
        return new LocalizedValue([]);
    }
}
