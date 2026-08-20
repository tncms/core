<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

/**
 * CORE-L10N.1B — the normalized result of resolving a language-switch target for one locale.
 *
 * It is the ONLY shape a theme renders. Themes must not know the strategy, the resolver, or
 * how the URL was built — they read these fields. It is produced by the Core
 * LocaleSwitchTargetService by combining language configuration + the selected resource
 * resolver + the active strategy.
 */
final class SwitchTarget
{
    /**
     * @param  array{action: ?string, fields: array<string, string>}|null  $action
     *         Core-owned switch action (e.g. a POST endpoint + hidden fields) for strategies
     *         that persist rather than link; null for plain GET link strategies.
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $label,
        public readonly string $nativeLabel,
        public readonly bool $active,
        public readonly bool $available,
        public readonly string $url,
        public readonly string $method,
        public readonly string $hreflang,
        public readonly bool $fallbackUsed,
        public readonly ?string $canonicalType,
        public readonly string|int|null $canonicalId,
        public readonly ?string $resolverKey,
        public readonly string $strategyKey,
        public readonly ?array $action = null,
        public readonly ?string $direction = null,
        public readonly ?string $flag = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'label' => $this->label,
            'native_label' => $this->nativeLabel,
            'active' => $this->active,
            'available' => $this->available,
            'url' => $this->url,
            'method' => $this->method,
            'hreflang' => $this->hreflang,
            'fallback_used' => $this->fallbackUsed,
            'canonical_type' => $this->canonicalType,
            'canonical_id' => $this->canonicalId,
            'resolver_key' => $this->resolverKey,
            'strategy_key' => $this->strategyKey,
            'action' => $this->action,
            'direction' => $this->direction,
            'flag' => $this->flag,
        ];
    }
}
