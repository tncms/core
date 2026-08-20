<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Content;

/**
 * The validation posture of a localized field (Phase 8.4): whether the default /
 * secondary locales are required, an optional max length, whether it is a slug,
 * and a locale-aware unique SEAM (a caller-supplied rule, wired by no one here).
 *
 * It is the bridge between a {@see LocalizedFieldDefinition} and the Phase 8.3
 * {@see \TheNguyen\CMS\Translation\Admin\LocalizedValidationRules}: {@see toOptions()}
 * returns exactly the options that rule builder consumes. Immutable.
 */
final class LocalizedValidationPolicy
{
    public function __construct(
        public readonly bool $requiredInDefaultLocale = true,
        public readonly bool $requiredInSecondaryLocales = false,
        public readonly ?int $maxLength = null,
        public readonly bool $slug = false,
        public readonly mixed $unique = null,
    ) {
    }

    /**
     * Options for {@see \TheNguyen\CMS\Translation\Admin\LocalizedValidationRules::rulesFor()}.
     *
     * @return array<string, mixed>
     */
    public function toOptions(): array
    {
        $options = [
            'required_default' => $this->requiredInDefaultLocale,
            'secondary_required' => $this->requiredInSecondaryLocales,
            'slug' => $this->slug,
        ];

        if ($this->maxLength !== null) {
            $options['max'] = $this->maxLength;
        }
        if ($this->unique !== null) {
            $options['unique'] = $this->unique;
        }

        return $options;
    }

    /** Attach a locale-aware unique rule (the seam) without mutating the original. */
    public function withUnique(mixed $rule): self
    {
        return new self(
            $this->requiredInDefaultLocale,
            $this->requiredInSecondaryLocales,
            $this->maxLength,
            $this->slug,
            $rule,
        );
    }
}
