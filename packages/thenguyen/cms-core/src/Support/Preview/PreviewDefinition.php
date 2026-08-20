<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Preview;

/**
 * A safe, immutable descriptor of a registered previewable type
 * (v1.0.0-beta.7.1.12.1).
 *
 * Returned by {@see \TheNguyen\CMS\Services\PreviewManager::definition()} /
 * {@see \TheNguyen\CMS\Services\PreviewManager::definitions()}. It intentionally
 * never carries the resolver/renderer callables — only presence flags and
 * descriptive metadata — so it is safe to expose to discovery / health without
 * leaking closures or model internals.
 */
final class PreviewDefinition
{
    public function __construct(
        public readonly string $type,
        public readonly bool $hasRenderer,
        public readonly ?string $label = null,
        public readonly ?string $version = null,
    ) {
    }

    /**
     * @return array{type: string, has_renderer: bool, label: ?string, version: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'has_renderer' => $this->hasRenderer,
            'label' => $this->label,
            'version' => $this->version,
        ];
    }
}
