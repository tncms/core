<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

use TheNguyen\CMS\Translation\Contracts\TranslationResolverInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\Support\LocalizedField;

/**
 * Resolves what the frontend would display for a locale, using the engine's
 * fallback chain (Phase 8.3).
 *
 * Read-only and never persisted: it exists so an editor can SEE the inherited
 * value for an untranslated locale. It uses the one {@see TranslationResolverInterface}
 * (and therefore the one fallback chain) — no custom fallback logic here.
 */
final class FallbackPreviewResolver
{
    public function __construct(private readonly TranslationResolverInterface $resolver)
    {
    }

    public function preview(LocalizedField $field, string $locale): FallbackPreviewResult
    {
        $result = $this->resolver->resolveValue(
            $field->value,
            new TranslationContext(requestedLocale: $locale),
        );

        $isFallback = $result->found()
            && $result->locale !== null
            && $result->locale !== $locale;

        return new FallbackPreviewResult(
            $result->value,
            $result->locale,
            $isFallback,
            $result->found(),
        );
    }

    /**
     * Preview directly from an admin state array (unsaved values), so the editor
     * sees the effect of what they are typing without persisting anything.
     *
     * @param array<string, string|null> $state
     */
    public function previewState(array $state, string $locale): FallbackPreviewResult
    {
        $map = [];
        foreach ($state as $code => $value) {
            if (is_string($value)) {
                $map[(string) $code] = $value;
            }
        }

        return $this->preview(new LocalizedField(new LocalizedValue($map)), $locale);
    }
}
