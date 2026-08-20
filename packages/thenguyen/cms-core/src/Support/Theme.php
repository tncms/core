<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Lightweight value object describing a discovered theme.
 *
 * This is a plain PHP object built from a theme's theme.json — it is not an
 * Eloquent model and is never persisted. The active theme slug lives in the
 * cms_settings table under "theme.active".
 */
final class Theme
{
    /**
     * @param  array<string, mixed>  $supports
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $author,
        public readonly string $description,
        public readonly string $path,
        public readonly ?string $screenshot,
        public readonly array $supports = [],
        public readonly ?string $authorUri = null,
        public readonly ?string $supportEmail = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'version' => $this->version,
            'author' => $this->author,
            'description' => $this->description,
            'path' => $this->path,
            'screenshot' => $this->screenshot,
            'supports' => $this->supports,
            'author_uri' => $this->authorUri,
            'support_email' => $this->supportEmail,
        ];
    }
}
