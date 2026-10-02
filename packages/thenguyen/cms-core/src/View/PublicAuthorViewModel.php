<?php

declare(strict_types=1);

namespace TheNguyen\CMS\View;

/**
 * Public author introduction (CORE-BLOG-1). Immutable, privacy-safe projection
 * of the content author. It carries ONLY fields safe for anonymous public
 * display: a display name, an optional avatar ViewModel, an optional public
 * biography, and an optional public profile URL (populated only when a verified
 * public author route exists — null today).
 *
 * It deliberately excludes email, phone, username/login, password/token, roles,
 * permissions, account status, locale preferences, and every other private
 * attribute. Themes consume this object, NEVER the raw App\Models\User model.
 */
final class PublicAuthorViewModel
{
    public function __construct(
        public readonly string $name,
        public readonly ?MediaViewModel $avatar = null,
        public readonly ?string $bio = null,
        public readonly ?string $url = null,
    ) {}

    /**
     * Stable, serializable shape. By construction it can only ever contain the
     * public fields above — there is no path for private user data to leak here.
     *
     * @return array{name: string, avatar: ?array<string, mixed>, bio: ?string, url: ?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'avatar' => $this->avatar?->toArray(),
            'bio' => $this->bio,
            'url' => $this->url,
        ];
    }
}
