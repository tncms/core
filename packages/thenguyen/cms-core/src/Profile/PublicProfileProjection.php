<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Profile;

use JsonSerializable;

/**
 * Immutable public projection of a consenting user's profile (CORE-EXTENSION-TRUST
 * S3). Holds plain text and arrays only — never a model, id, email, username,
 * role or token. Values are NOT HTML: consumers escape them (Blade `{{ }}`).
 */
final readonly class PublicProfileProjection implements JsonSerializable
{
    /**
     * @param  array{url: string, alt: string}|null  $avatar
     * @param  list<array{key: string, type: string, label: string, display: string, href: ?string}>  $fields
     */
    public function __construct(
        public ?string $name = null,
        public ?array $avatar = null,
        public ?string $bio = null,
        public array $fields = [],
    ) {}

    /**
     * Stable shape: always exactly these four keys.
     *
     * @return array{name: ?string, avatar: ?array{url: string, alt: string}, bio: ?string, fields: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'fields' => $this->fields,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
