<?php

declare(strict_types=1);

namespace App\Search\Providers;

use App\Search\Contracts\SearchableEntityDefinition;
use TheNguyen\CMS\Models\Content;

/**
 * Declares the "content" searchable entity — the CMS's own Posts and Pages
 * (CORE-SEARCH-1, GAP 1). cms-core stores both under one unified {@see Content}
 * model discriminated by a `type` column, so this is ONE searchable type with the
 * post/page subtype carried in result metadata, never an artificial split.
 *
 * Metadata only: the owning {@see ContentSearchProvider} performs retrieval through
 * the Content model's canonical published/locale scopes, and titles/URLs resolve
 * through the same locale-aware authority the frontend uses ({@see Content::translatedTitle()},
 * {@see content_url()}). No draft, trashed, or unpublished-translation record is
 * ever exposed.
 */
final class ContentSearchDefinition implements SearchableEntityDefinition
{
    public const TYPE = 'content';

    /**
     * Localized editorial columns on `cms_content_translations` that keyword
     * matching scans. Editorial text only — no author, status, or internal column.
     *
     * @var list<string>
     */
    public const SEARCHABLE_FIELDS = ['title', 'excerpt', 'content'];

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return function_exists('core_trans') ? core_trans('Posts & Pages') : 'Posts & Pages';
    }

    public function modelClass(): string
    {
        return Content::class;
    }

    /**
     * @return list<string>
     */
    public function searchableFields(): array
    {
        return self::SEARCHABLE_FIELDS;
    }

    public function supportsLocales(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function visibilityRules(): array
    {
        return ['published', 'non-deleted', 'published-translations-only'];
    }

    public function resolveTitle(object $entity, string $locale): string
    {
        return $entity instanceof Content ? $entity->translatedTitle($locale) : '';
    }

    public function resolveUrl(object $entity, string $locale): ?string
    {
        if (! $entity instanceof Content) {
            return null;
        }

        // content_url() returns the '#' sentinel when the locale has no resolvable
        // public slug; map that back to this contract's null (no destination).
        $url = content_url($entity, $locale);

        return $url === '#' ? null : $url;
    }
}
