<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\DTOs;

use TheNguyen\CMS\Translation\Enums\TranslationStage;

/**
 * Immutable outcome of a resolution: the resolved `value`, the `locale` it came
 * from (null for raw/miss), the {@see TranslationStage} that produced it, and
 * whether it was served `fromCache`.
 */
final class TranslationResult
{
    public function __construct(
        public readonly ?string $value,
        public readonly ?string $locale,
        public readonly TranslationStage $stage,
        public readonly bool $fromCache = false,
    ) {
    }

    /** A value resolved at a specific locale/stage. */
    public static function hit(string $value, string $locale, TranslationStage $stage): self
    {
        return new self($value, $locale, $stage, false);
    }

    /** The untranslated source value (no locale). */
    public static function raw(?string $value): self
    {
        return new self($value, null, TranslationStage::Raw, false);
    }

    /** Nothing matched anywhere in the chain. */
    public static function miss(): self
    {
        return new self(null, null, TranslationStage::Miss, false);
    }

    /** Whether the chain produced anything (raw counts as found). */
    public function found(): bool
    {
        return $this->stage !== TranslationStage::Miss;
    }

    public function withCache(bool $fromCache = true): self
    {
        return new self($this->value, $this->locale, $this->stage, $fromCache);
    }
}
