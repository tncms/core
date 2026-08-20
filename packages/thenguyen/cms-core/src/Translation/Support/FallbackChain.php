<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Support;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationResult;
use TheNguyen\CMS\Translation\Enums\TranslationStage;

/**
 * The one place that walks the fallback chain over a {@see LocalizedValue}.
 *
 * Both the resolver and {@see LocalizedField} delegate here, so the ordering
 * rules live in a single implementation (no duplicated localization logic). The
 * chain is data-driven from {@see TranslationContext::$fallbackChain}:
 *
 *   requested → site fallback → default locale → raw value
 *
 * An empty string counts as absent, matching the leaf semantics of
 * {@see \TheNguyen\CMS\Support\LocalizedValue}.
 */
final class FallbackChain
{
    public function resolve(LocalizedValue $value, TranslationContext $context): TranslationResult
    {
        foreach ($context->fallbackChain as $stageName) {
            $stage = $this->stageFor($stageName);
            if ($stage === null) {
                continue;
            }

            if ($stage === TranslationStage::Raw) {
                if ($value->raw !== null && $value->raw !== '') {
                    return TranslationResult::raw($value->raw);
                }

                continue;
            }

            $locale = $this->localeFor($stage, $context);
            if ($locale === null || $locale === '') {
                continue;
            }

            $resolved = $value->get($locale);
            if ($resolved !== null) {
                return TranslationResult::hit($resolved, $locale, $stage);
            }
        }

        return TranslationResult::miss();
    }

    private function stageFor(string $name): ?TranslationStage
    {
        return match ($name) {
            'requested' => TranslationStage::Requested,
            'site_fallback' => TranslationStage::SiteFallback,
            'default' => TranslationStage::DefaultLocale,
            'raw' => TranslationStage::Raw,
            default => null,
        };
    }

    private function localeFor(TranslationStage $stage, TranslationContext $context): ?string
    {
        return match ($stage) {
            TranslationStage::Requested => $context->requestedLocale,
            TranslationStage::SiteFallback => $context->fallbackLocale,
            TranslationStage::DefaultLocale => $context->defaultLocale,
            default => null,
        };
    }
}
