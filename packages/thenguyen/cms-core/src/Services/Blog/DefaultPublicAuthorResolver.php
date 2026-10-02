<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services\Blog;

use TheNguyen\CMS\Contracts\PublicAuthorResolver;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\View\MediaViewModel;
use TheNguyen\CMS\View\PublicAuthorViewModel;

/**
 * Default public-author projection (CORE-BLOG-1).
 *
 * Converts the content author into a privacy-safe PublicAuthorViewModel reading
 * ONLY public-presentation fields (display name, avatar, biography). It never
 * touches email, phone, username/login, password/token, roles, permissions,
 * account status, or locale preferences, and never returns the raw user model.
 *
 * Returns null for a guest/missing/deleted author or one with no usable display
 * name, so the theme simply omits the author block. There is no public author
 * profile route today, so the profile URL stays null.
 */
class DefaultPublicAuthorResolver implements PublicAuthorResolver
{
    public function forContent(Content $content, string $locale): ?PublicAuthorViewModel
    {
        $author = $content->author;

        if ($author === null) {
            return null;
        }

        $name = is_string($author->name ?? null) ? trim($author->name) : '';

        if ($name === '') {
            return null;
        }

        $avatar = null;
        $avatarUrl = $author->avatar ?? null;

        if (is_string($avatarUrl) && $avatarUrl !== '') {
            $avatar = MediaViewModel::fromUrl($avatarUrl, $name);
        }

        $bioRaw = $author->bio ?? null;
        $bio = is_string($bioRaw) && trim($bioRaw) !== '' ? $bioRaw : null;

        return new PublicAuthorViewModel(
            name: $name,
            avatar: $avatar,
            bio: $bio,
            url: null,
        );
    }
}
