<?php

declare(strict_types=1);

namespace App\Search\Presentation;

use App\Search\Providers\ContentSearchDefinition;
use App\Search\SearchResult;
use TheNguyen\CMS\Content\Extension\ContentOwnershipMarker;

/**
 * A single search hit shaped for the Blade view (Phase 3.1.6N-D). Presentation
 * only — title/excerpt/url are already localized by the provider, and the type
 * is resolved to its human label. No model, no commercial data.
 *
 * A Core content hit also carries its content id and, when a plugin manages
 * it, the public ownership marker (CORE-38-TOPLIST-SEAMS-R1), so a theme can
 * present owner-managed results by its own rules without URL guessing, title
 * matching or extra queries. Title and excerpt stay Core's; the marker says
 * who manages the item, never that its Core metadata is owner-approved.
 */
final class SearchResultView
{
    public function __construct(
        public readonly string $title,
        public readonly string $excerpt,
        public readonly ?string $url,
        public readonly string $typeLabel,
        public readonly float $score,
        public readonly ?int $contentId = null,
        public readonly ?ContentOwnershipMarker $ownership = null,
    ) {}

    public static function fromResult(SearchResult $result, string $typeLabel, ?ContentOwnershipMarker $ownership = null): self
    {
        $contentId = self::contentIdOf($result);

        return new self(
            title: $result->title,
            excerpt: $result->excerpt,
            url: $result->url,
            typeLabel: $typeLabel,
            score: $result->score,
            contentId: $contentId,
            ownership: $contentId !== null ? $ownership : null,
        );
    }

    /** The Core content id of a content hit; null for any other result type. */
    public static function contentIdOf(SearchResult $result): ?int
    {
        if ($result->type !== ContentSearchDefinition::TYPE) {
            return null;
        }

        return is_int($result->id) ? $result->id : (ctype_digit($result->id) ? (int) $result->id : null);
    }
}
