<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets;

use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Widgets\Fields\ToggleField;

/**
 * Lists category terms that have a translation in the active locale, linking
 * each to its locale-aware archive URL. Optionally shows each term's post count.
 */
class CategoriesWidget extends Widget
{
    public static function type(): string
    {
        return 'categories';
    }

    public static function name(): string
    {
        return core_trans('Categories');
    }

    public static function description(): string
    {
        return core_trans('A list of content categories.');
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-folder';
    }

    public static function group(): string
    {
        return 'Content';
    }

    public static function schema(): array
    {
        return [
            ToggleField::make('show_count')->label(core_trans('Show post count'))->default(false),
        ];
    }

    public function render(array $settings = [], ?string $locale = null): string
    {
        $locale ??= current_locale();
        $showCount = filter_var($settings['show_count'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $title = trim((string) ($settings['title'] ?? ''));

        // Strictly per-locale: only categories that actually have a translation
        // (name + slug) in the CURRENT locale are shown, with that locale's name
        // and URL. No cross-locale fallback — a category not translated into the
        // current language is simply omitted rather than shown in another language.
        $terms = Term::query()
            ->whereHas('taxonomy', static fn ($q) => $q->where('type', 'category'))
            ->whereHas('translations', static fn ($q) => $q->where('locale', $locale)->whereNotNull('slug'))
            ->with(['taxonomy', 'translations'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $items = '';

        foreach ($terms as $term) {
            $url = term_url($term, $locale);
            $name = $term->localeName($locale);

            // Skip if the current locale has no resolvable URL or name.
            if ($url === '#' || $name === null) {
                continue;
            }

            $items .= '<li class="tn-widget__item">'
                .'<a href="'.e($url).'">'.e($name).'</a>';

            if ($showCount) {
                $items .= ' <span class="tn-widget__count">('.(int) $term->count.')</span>';
            }

            $items .= '</li>';
        }

        if ($items === '') {
            return '';
        }

        $html = '<div class="tn-widget tn-widget--categories">';

        if ($title !== '') {
            $html .= '<h2 class="tn-widget__title">'.e($title).'</h2>';
        }

        return $html.'<ul class="tn-widget__list">'.$items.'</ul></div>';
    }
}
