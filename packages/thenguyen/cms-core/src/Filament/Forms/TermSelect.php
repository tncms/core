<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Forms;

use Closure;
use Filament\Forms\Components\Select;

/**
 * CORE-P16-1 — searchable term picker backed by the Core taxonomy directory.
 *
 * Never preloads the taxonomy: the dropdown opens on one bounded first page, typing runs a
 * bounded server-side directory search, and selected ids are labelled by exact-id lookup (so a
 * selection persists across save, reopen, search changes and reloads even when it is not on the
 * first page). Submitted ids are validated by the same lookup.
 *
 *     TermSelect::make('category_ids')->multiple()->taxonomy('category')->termLocale(fn () => 'vi')
 *
 * - taxonomy(): 'category' | 'tag'.
 * - termLocale(): locale for labels, matching and validation; defaults to editing_locale(), and
 *   falls back to the site default locale when the given one is not an enabled site language.
 * - requireTermTranslation(): true (default) lists and accepts only terms named in that locale;
 *   false lists every term with fallback labels (requested → default → first → "Term #id").
 *
 * Authorization is the host page's: the component adds no endpoint of its own; its Livewire
 * calls run inside the page/resource that renders it.
 */
class TermSelect extends Select
{
    protected string|Closure $termTaxonomy = 'category';

    protected string|Closure|null $termLocale = null;

    protected bool|Closure $requireTermTranslation = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->searchable()
            ->options(static fn (TermSelect $component): array => TermPickerOptions::firstPage(
                $component->getTermTaxonomy(), $component->getTermLocale(), $component->requiresTermTranslation(),
            ))
            ->getSearchResultsUsing(static fn (TermSelect $component, ?string $search): array => TermPickerOptions::search(
                $component->getTermTaxonomy(), $component->getTermLocale(), (string) $search, $component->requiresTermTranslation(),
            ))
            ->getOptionLabelsUsing(static fn (TermSelect $component, ?array $values): array => TermPickerOptions::labels(
                $values ?? [], $component->getTermTaxonomy(), $component->getTermLocale(), $component->requiresTermTranslation(),
            ))
            ->getOptionLabelUsing(static fn (TermSelect $component, mixed $value): ?string => $value === null || $value === ''
                ? null
                : (TermPickerOptions::labels(
                    [$value], $component->getTermTaxonomy(), $component->getTermLocale(), $component->requiresTermTranslation(),
                )[(int) $value] ?? null));
    }

    public function taxonomy(string|Closure $taxonomy): static
    {
        $this->termTaxonomy = $taxonomy;

        return $this;
    }

    public function termLocale(string|Closure|null $locale): static
    {
        $this->termLocale = $locale;

        return $this;
    }

    public function requireTermTranslation(bool|Closure $condition = true): static
    {
        $this->requireTermTranslation = $condition;

        return $this;
    }

    public function getTermTaxonomy(): string
    {
        return (string) $this->evaluate($this->termTaxonomy);
    }

    public function getTermLocale(): string
    {
        $locale = $this->evaluate($this->termLocale);
        $locale = is_string($locale) && $locale !== '' ? $locale : editing_locale();
        $config = app('cms.localization.config');

        // An admin UI locale need not be a site language; pick terms in the site default then.
        return $config->isEnabled($config->normalize($locale)) ? $locale : $config->defaultLocale();
    }

    public function requiresTermTranslation(): bool
    {
        return (bool) $this->evaluate($this->requireTermTranslation);
    }
}
