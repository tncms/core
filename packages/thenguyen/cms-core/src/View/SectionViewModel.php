<?php

declare(strict_types=1);

namespace TheNguyen\CMS\View;

/**
 * A resolved section, ready for rendering by `theme::sections.{type}`
 * (theme-architecture `17` §3, §9). Produced by the {@see \TheNguyen\CMS\Services\SectionResolver}
 * from a canonical pagebuilder/06 section node: settings are validated +
 * defaulted, and data holds typed values (scalars, nested item arrays, and
 * {@see MediaViewModel} instances) — never raw Eloquent models.
 *
 * Blade reads VM properties only; it never queries or touches relations
 * (theme-architecture `20` Rules 1–4).
 */
final class SectionViewModel
{
    /**
     * @param  array<string, mixed>  $settings  validated presentation knobs
     * @param  array<string, mixed>  $data  resolved content fields
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $settings = [],
        public readonly array $data = [],
    ) {}

    /**
     * A validated presentation setting (with caller fallback).
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * A resolved content field (with caller fallback).
     */
    public function field(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Round-trip to the pagebuilder/06 node shape (stable keys for storage,
     * the JSON renderer, and the AI Builder). MediaViewModels are serialized
     * via their own toArray().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'settings' => $this->settings,
            'fields' => $this->normalize($this->data),
        ];
    }

    /**
     * Recursively convert MediaViewModels (and nested arrays of them) to arrays.
     */
    private function normalize(mixed $value): mixed
    {
        if ($value instanceof MediaViewModel) {
            return $value->toArray();
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalize($item), $value);
        }

        return $value;
    }
}
