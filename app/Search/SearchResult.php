<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Immutable, view-ready search hit (Phase 3.1.6N-B foundation). A result is a
 * REFERENCE to an entity — its identity plus already-localized presentation data
 * — never a full model. Callers hand the (type, id) pair back to the owning
 * repository / render pipeline when a page must actually be built.
 */
final class SearchResult
{
    /**
     * @param  array<string, mixed>  $metadata  Optional, non-sensitive extras
     *                                           (matched fields, badges, thumb).
     */
    public function __construct(
        public readonly string $type,
        public readonly int|string $id,
        public readonly string $title,
        public readonly string $excerpt,
        public readonly ?string $url,
        public readonly float $score,
        public readonly string $locale,
        public readonly array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'url' => $this->url,
            'score' => $this->score,
            'locale' => $this->locale,
            'metadata' => $this->metadata,
        ];
    }
}
