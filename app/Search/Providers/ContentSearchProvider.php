<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\Search\Contracts\SearchProvider;
use App\Search\SearchQuery;
use App\Search\SearchResult;
use Illuminate\Support\Str;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;

/**
 * The CMS's own Post/Page search into the Shared Search Platform (CORE-SEARCH-1,
 * GAP 1). This is the Core default provider — always registered, no plugin toggle.
 *
 * Retrieval composes the Content model's CANONICAL scopes exactly as the public
 * frontend does ({@see Content::scopePublished()} + a per-locale translation whose
 * slug is present), so publication and locale rules are honoured once and never
 * duplicated here. Only published, non-deleted content that has a resolvable public
 * URL in the query locale is returned — drafts, trashed rows, and unpublished
 * translations can never leak. Results carry (type, id) references and already-
 * localized presentation only, never a Content model.
 */
final class ContentSearchProvider implements SearchProvider
{
    public const KEY = 'content';

    private const EXCERPT_LENGTH = 200;

    /** Hard cap on rows hydrated for one query — keeps the read bounded. */
    private const MAX_RESULTS = 50;

    public function __construct(private readonly ContentSearchDefinition $definition) {}

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @return list<\App\Search\Contracts\SearchableEntityDefinition>
     */
    public function definitions(): array
    {
        return [$this->definition];
    }

    /**
     * @return list<SearchResult>
     */
    public function search(SearchQuery $query): array
    {
        if (! $query->wantsType(ContentSearchDefinition::TYPE) || $query->keyword === '') {
            return [];
        }

        $locale = $query->locale !== '' ? $query->locale : current_locale();
        $like = '%'.$query->keyword.'%';

        $contents = Content::query()
            ->whereIn('type', ['post', 'page'])
            ->published()
            ->whereHas('translations', function ($q) use ($locale, $like): void {
                $q->where('locale', $locale)
                    ->whereNotNull('slug')
                    ->where('slug', '!=', '')
                    ->where(function ($m) use ($like): void {
                        foreach (ContentSearchDefinition::SEARCHABLE_FIELDS as $field) {
                            $m->orWhere($field, 'like', $like);
                        }
                    });
            })
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::MAX_RESULTS)
            ->get();

        $results = [];
        foreach ($contents as $content) {
            if ($result = $this->toResult($content, $locale, $query->keyword)) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function toResult(Content $content, string $locale, string $keyword): ?SearchResult
    {
        // Skip anything without a canonical public destination in this locale —
        // the provider owns visibility; a hit a visitor cannot open is not a hit.
        $url = $this->definition->resolveUrl($content, $locale);
        if ($url === null) {
            return null;
        }

        $title = $this->definition->resolveTitle($content, $locale);
        $translation = $content->relationLoaded('translations')
            ? $content->translations->firstWhere('locale', $locale)
            : null;

        return new SearchResult(
            type: ContentSearchDefinition::TYPE,
            id: (int) $content->id,
            title: $title,
            excerpt: $this->excerpt($translation),
            url: $url,
            score: $this->score($title, $keyword),
            locale: $locale,
            metadata: ['subtype' => $content->type],
        );
    }

    /** Plain-text summary: the editorial excerpt, else a trimmed body. */
    private function excerpt(?ContentTranslation $translation): string
    {
        if ($translation === null) {
            return '';
        }

        $excerpt = trim(strip_tags((string) ($translation->excerpt ?? '')));
        if ($excerpt !== '') {
            return Str::limit($excerpt, self::EXCERPT_LENGTH);
        }

        $body = trim(strip_tags((string) ($translation->content ?? '')));

        return $body !== '' ? Str::limit($body, self::EXCERPT_LENGTH) : '';
    }

    /** Title hits rank above body/excerpt-only hits. Deterministic, no DB. */
    private function score(string $title, string $keyword): float
    {
        return $keyword !== '' && mb_stripos($title, $keyword) !== false ? 2.0 : 1.0;
    }
}
