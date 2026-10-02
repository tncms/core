<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services\Blog;

use Illuminate\Database\Eloquent\Builder;
use TheNguyen\CMS\Contracts\AdjacentPostResolver;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\View\AdjacentPostViewModel;

/**
 * Default adjacent-post navigation (CORE-BLOG-1).
 *
 * Semantics (frozen):
 * - "previous" = the immediately OLDER post; "next" = the immediately NEWER post.
 * - Candidates are the same content type (post), published and publicly visible
 *   (reuses the Content `published()` scope + SoftDeletes), and have a slugged
 *   translation in the active locale.
 * - Ordering authority is `published_at` with a stable `id` tie-breaker, so the
 *   result is deterministic even when two posts share a timestamp.
 * - A first/last post returns null on the missing side.
 * - A post without a publication timestamp has no computable adjacency (null).
 *
 * Two bounded single-row queries; `published_at` is indexed.
 */
class DefaultAdjacentPostResolver implements AdjacentPostResolver
{
    public function previous(Content $post, string $locale): ?AdjacentPostViewModel
    {
        return $this->adjacent($post, $locale, older: true);
    }

    public function next(Content $post, string $locale): ?AdjacentPostViewModel
    {
        return $this->adjacent($post, $locale, older: false);
    }

    private function adjacent(Content $post, string $locale, bool $older): ?AdjacentPostViewModel
    {
        $pivotAt = $post->published_at;

        if ($pivotAt === null) {
            return null;
        }

        $pivotId = (int) $post->getKey();

        $query = Content::query()
            ->posts()
            ->published()
            ->whereNotNull('published_at')
            ->whereKeyNot($pivotId)
            ->whereHas('translations', static fn ($q) => $q->where('locale', $locale)->whereNotNull('slug'))
            ->with(['translations']);

        if ($older) {
            $query
                ->where(static function (Builder $q) use ($pivotAt, $pivotId): void {
                    $q->where('published_at', '<', $pivotAt)
                        ->orWhere(static fn (Builder $qq) => $qq
                            ->where('published_at', $pivotAt)
                            ->where('id', '<', $pivotId));
                })
                ->orderByDesc('published_at')
                ->orderByDesc('id');
        } else {
            $query
                ->where(static function (Builder $q) use ($pivotAt, $pivotId): void {
                    $q->where('published_at', '>', $pivotAt)
                        ->orWhere(static fn (Builder $qq) => $qq
                            ->where('published_at', $pivotAt)
                            ->where('id', '>', $pivotId));
                })
                ->orderBy('published_at')
                ->orderBy('id');
        }

        $found = $query->first();

        if ($found === null) {
            return null;
        }

        $url = content_url($found, $locale);

        if ($url === '#') {
            return null;
        }

        return new AdjacentPostViewModel(
            title: $found->translatedTitle($locale),
            url: $url,
            publishedAt: $found->published_at,
        );
    }
}
