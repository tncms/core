<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;

/**
 * Builds the id→label option lists the Editorial Query Builder UI offers for its
 * term / content / author pickers (theme-implementation; Phase 9C-C).
 *
 * Lives in cms-core (not the Filament page) because it is reusable query + locale
 * logic, not a UI concern: it queries terms/posts/authors and resolves admin-facing
 * labels through the language fallback chain. The labels are display-only — stored
 * section config keeps ids exclusively (see {@see SectionResolver::coerceIdList()}).
 */
class EditorialOptions
{
    /**
     * Term id → admin-locale label for a taxonomy type. Label fallback:
     * current locale → default locale → first translation → "Term #ID".
     *
     * @return array<int, string>
     */
    public function termOptions(string $taxonomyType): array
    {
        [$locale, $default] = $this->locales();

        return Term::query()
            ->whereHas('taxonomy', static fn ($q) => $q->where('type', $taxonomyType))
            ->with('translations')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Term $term): array => [$term->id => $this->termLabel($term, $locale, $default)])
            ->all();
    }

    /**
     * Content (post) id → admin-locale title. Fallback: the model resolves
     * current → default → first; an empty title degrades to "Post #ID".
     *
     * @return array<int, string>
     */
    public function contentOptions(): array
    {
        [$locale] = $this->locales();

        return Content::query()
            ->where('type', 'post')
            ->with('translations')
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (Content $content): array => [
                $content->id => $this->contentLabel($content, $locale),
            ])
            ->all();
    }

    /**
     * Author (user) id → label. Fallback: name → email → "Author #ID".
     *
     * @return array<int|string, string>
     */
    public function authorOptions(): array
    {
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $userModel::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn ($user): array => [$user->getKey() => $this->authorLabel($user)])
            ->all();
    }

    /**
     * @return array{0: string, 1: string}  [current admin locale, default locale]
     */
    private function locales(): array
    {
        $languages = app('cms.language');

        return [$languages->currentCode(), $languages->defaultCode()];
    }

    private function termLabel(Term $term, string $locale, string $default): string
    {
        $name = $term->localeName($locale);

        if (is_string($name) && $name !== '') {
            return $name;
        }

        if ($default !== $locale) {
            $name = $term->localeName($default);

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        $first = $term->translations->first();

        if (is_string($first?->name) && $first->name !== '') {
            return $first->name;
        }

        return 'Term #'.$term->id;
    }

    private function contentLabel(Content $content, string $locale): string
    {
        $title = $content->translatedTitle($locale);

        return is_string($title) && $title !== '' ? $title : 'Post #'.$content->id;
    }

    private function authorLabel(mixed $user): string
    {
        if (is_string($user->name ?? null) && $user->name !== '') {
            return $user->name;
        }

        if (is_string($user->email ?? null) && $user->email !== '') {
            return $user->email;
        }

        return 'Author #'.$user->getKey();
    }
}
