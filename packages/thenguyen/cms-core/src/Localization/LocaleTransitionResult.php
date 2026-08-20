<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

/**
 * CORE-L10N A2 — the immutable diagnostics record of one locale transition.
 *
 * It exposes exactly what a future `cms:localization:diagnose` needs — the
 * current, requested and resolved locales, whether a preference was persisted,
 * the safe redirect target, and the outcome — and nothing else. It carries no
 * request, no HTML, and no plugin knowledge.
 */
final class LocaleTransitionResult
{
    private function __construct(
        public readonly string $currentLocale,
        public readonly string $requestedLocale,
        public readonly ?string $resolvedLocale,
        public readonly bool $persisted,
        public readonly string $redirectTarget,
        public readonly LocaleTransitionStatus $status,
    ) {}

    public static function changed(string $current, string $requested, string $resolved, string $redirect): self
    {
        return new self($current, $requested, $resolved, true, $redirect, LocaleTransitionStatus::Changed);
    }

    public static function unchanged(string $current, string $requested, string $resolved, string $redirect): self
    {
        return new self($current, $requested, $resolved, true, $redirect, LocaleTransitionStatus::Unchanged);
    }

    public static function rejected(string $current, string $requested, string $redirect): self
    {
        return new self($current, $requested, null, false, $redirect, LocaleTransitionStatus::Rejected);
    }

    /** @return array{current_locale:string,requested_locale:string,resolved_locale:?string,persisted:bool,redirect_target:string,status:string} */
    public function toArray(): array
    {
        return [
            'current_locale' => $this->currentLocale,
            'requested_locale' => $this->requestedLocale,
            'resolved_locale' => $this->resolvedLocale,
            'persisted' => $this->persisted,
            'redirect_target' => $this->redirectTarget,
            'status' => $this->status->value,
        ];
    }
}
