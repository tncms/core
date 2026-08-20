<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

/**
 * CORE-L10N.1B — an immutable snapshot of a single locale's presentation metadata.
 *
 * It carries locale FACTS only (code, names, direction, flag, default flag). It holds no
 * URL, prefix, request, or persistence handle, so it is safe to pass to plugins and themes
 * and cannot leak mutable state. It is sourced from the canonical {@see \TheNguyen\CMS\Services\LanguageManager}
 * (`cms_languages`) — never from plugin configuration.
 */
final class LocaleMetadata
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly string $nativeName,
        public readonly string $direction,
        public readonly ?string $flag,
        public readonly bool $isDefault,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'native_name' => $this->nativeName,
            'direction' => $this->direction,
            'flag' => $this->flag,
            'is_default' => $this->isDefault,
        ];
    }
}
