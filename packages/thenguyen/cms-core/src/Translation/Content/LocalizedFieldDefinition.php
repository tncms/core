<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Content;

use TheNguyen\CMS\Translation\Content\Enums\LocalizedFallbackPolicy;
use TheNguyen\CMS\Translation\Content\Enums\LocalizedFieldType;

/**
 * The declarative description of ONE localized field of a content type (Phase 8.4):
 * its name, {@see LocalizedFieldType}, fallback policy, validation posture and
 * capability flags (searchable / indexable / slug-aware / HTML / max length).
 *
 * Definitions start from the type's defaults and override per field. Immutable.
 * The convenience factories (title(), slug(), richContent(), …) cover the
 * standard vocabulary; custom() covers everything else.
 */
final class LocalizedFieldDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly LocalizedFieldType $type,
        public readonly LocalizedFallbackPolicy $fallback,
        public readonly bool $requiredInDefaultLocale,
        public readonly bool $requiredInSecondaryLocales,
        public readonly bool $searchable,
        public readonly bool $indexable,
        public readonly bool $slugAware,
        public readonly bool $html,
        public readonly ?int $maxLength = null,
    ) {
    }

    /**
     * Build a definition from a type, overriding any default via $overrides:
     * fallback, required_default, required_secondary, searchable, indexable,
     * slug_aware, html, max_length.
     *
     * @param array<string, mixed> $overrides
     */
    public static function make(string $name, LocalizedFieldType $type = LocalizedFieldType::Custom, array $overrides = []): self
    {
        return new self(
            $name,
            $type,
            $overrides['fallback'] ?? $type->fallback(),
            (bool) ($overrides['required_default'] ?? $type->requiredInDefaultLocale()),
            (bool) ($overrides['required_secondary'] ?? false),
            (bool) ($overrides['searchable'] ?? $type->searchable()),
            (bool) ($overrides['indexable'] ?? $type->indexable()),
            (bool) ($overrides['slug_aware'] ?? $type->slugAware()),
            (bool) ($overrides['html'] ?? $type->html()),
            isset($overrides['max_length']) ? (int) $overrides['max_length'] : null,
        );
    }

    // Standard-vocabulary factories --------------------------------------------

    /** @param array<string, mixed> $o */
    public static function title(string $name = 'title', array $o = []): self
    {
        return self::make($name, LocalizedFieldType::Title, $o);
    }

    /** @param array<string, mixed> $o */
    public static function slug(string $name = 'slug', array $o = []): self
    {
        return self::make($name, LocalizedFieldType::Slug, $o);
    }

    /** @param array<string, mixed> $o */
    public static function excerpt(string $name = 'excerpt', array $o = []): self
    {
        return self::make($name, LocalizedFieldType::Excerpt, $o);
    }

    /** @param array<string, mixed> $o */
    public static function summary(string $name = 'summary', array $o = []): self
    {
        return self::make($name, LocalizedFieldType::Summary, $o);
    }

    /** @param array<string, mixed> $o */
    public static function content(string $name = 'content', array $o = []): self
    {
        return self::make($name, LocalizedFieldType::Content, $o);
    }

    /** @param array<string, mixed> $o */
    public static function richContent(string $name = 'content', array $o = []): self
    {
        return self::make($name, LocalizedFieldType::RichContent, $o);
    }

    /** @param array<string, mixed> $o */
    public static function markdown(string $name = 'body', array $o = []): self
    {
        return self::make($name, LocalizedFieldType::Markdown, $o);
    }

    /** @param array<string, mixed> $o */
    public static function seoTitle(string $name = 'seo_title', array $o = []): self
    {
        return self::make($name, LocalizedFieldType::SeoTitle, $o);
    }

    /** @param array<string, mixed> $o */
    public static function seoDescription(string $name = 'seo_description', array $o = []): self
    {
        return self::make($name, LocalizedFieldType::SeoDescription, $o);
    }

    /** @param array<string, mixed> $o */
    public static function seoKeywords(string $name = 'seo_keywords', array $o = []): self
    {
        return self::make($name, LocalizedFieldType::SeoKeywords, $o);
    }

    /** @param array<string, mixed> $o */
    public static function meta(string $name, array $o = []): self
    {
        return self::make($name, LocalizedFieldType::Meta, $o);
    }

    /** @param array<string, mixed> $o */
    public static function custom(string $name, array $o = []): self
    {
        return self::make($name, LocalizedFieldType::Custom, $o);
    }

    // --------------------------------------------------------------------------

    /** The validation posture derived from this definition. */
    public function validationPolicy(): LocalizedValidationPolicy
    {
        return new LocalizedValidationPolicy(
            $this->requiredInDefaultLocale,
            $this->requiredInSecondaryLocales,
            $this->maxLength,
            $this->slugAware,
        );
    }

    public function withFallback(LocalizedFallbackPolicy $fallback): self
    {
        return new self(
            $this->name, $this->type, $fallback,
            $this->requiredInDefaultLocale, $this->requiredInSecondaryLocales,
            $this->searchable, $this->indexable, $this->slugAware, $this->html, $this->maxLength,
        );
    }

    /** @return array<string, mixed> introspection view for docs/tooling */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'fallback' => $this->fallback->value,
            'required_default' => $this->requiredInDefaultLocale,
            'required_secondary' => $this->requiredInSecondaryLocales,
            'searchable' => $this->searchable,
            'indexable' => $this->indexable,
            'slug_aware' => $this->slugAware,
            'html' => $this->html,
            'max_length' => $this->maxLength,
        ];
    }
}
