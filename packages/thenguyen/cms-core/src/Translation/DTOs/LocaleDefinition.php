<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\DTOs;

/**
 * Immutable locale entry held by the {@see \TheNguyen\CMS\Translation\Locale\LocaleRegistry}.
 *
 * `code` is a BCP-47-ish locale code (e.g. "en", "vi"); `label` is a
 * human-facing name; `native` is the endonym; `enabled` flags whether the
 * locale participates in resolution. No locale is hardcoded here — the registry
 * is seeded from config.
 */
final class LocaleDefinition
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly bool $enabled = true,
        public readonly ?string $native = null,
    ) {
    }

    public static function make(string $code, ?string $label = null, bool $enabled = true, ?string $native = null): self
    {
        return new self($code, $label ?? $code, $enabled, $native);
    }

    public function withLabel(string $label): self
    {
        return new self($this->code, $label, $this->enabled, $this->native);
    }

    public function withEnabled(bool $enabled): self
    {
        return new self($this->code, $this->label, $enabled, $this->native);
    }

    public function withNative(?string $native): self
    {
        return new self($this->code, $this->label, $this->enabled, $native);
    }
}
