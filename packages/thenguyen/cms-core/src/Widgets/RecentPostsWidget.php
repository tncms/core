<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Widgets\Fields\NumberField;
use TheNguyen\CMS\Widgets\Fields\ToggleField;

/**
 * Lists the most recent published posts that have a translation in the active
 * locale, linking each to its locale-aware URL. The configured limit is always
 * clamped to a safe range so a hostile/garbage value cannot run an unbounded
 * query.
 */
class RecentPostsWidget extends Widget
{
    private const LIMIT_MIN = 1;

    private const LIMIT_MAX = 20;

    private const LIMIT_DEFAULT = 5;

    public static function type(): string
    {
        return 'recent-posts';
    }

    public static function name(): string
    {
        return core_trans('Recent Posts');
    }

    public static function description(): string
    {
        return core_trans('The latest published posts.');
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-newspaper';
    }

    public static function group(): string
    {
        return 'Content';
    }

    public static function schema(): array
    {
        return [
            NumberField::make('limit')->label(core_trans('Number of posts'))->default(self::LIMIT_DEFAULT)->min(self::LIMIT_MIN)->max(self::LIMIT_MAX),
            ToggleField::make('show_date')->label(core_trans('Show date'))->default(true),
        ];
    }

    public function render(array $settings = [], ?string $locale = null): string
    {
        $locale ??= current_locale();
        $limit = $this->clampLimit($settings['limit'] ?? self::LIMIT_DEFAULT);
        $showDate = filter_var($settings['show_date'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $title = trim((string) ($settings['title'] ?? ''));

        $posts = Content::query()
            ->posts()
            ->published()
            ->whereHas('translations', static fn ($q) => $q->where('locale', $locale)->whereNotNull('slug'))
            ->with('translations')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $items = '';

        foreach ($posts as $post) {
            $url = content_url($post, $locale);

            if ($url === '#') {
                continue;
            }

            $items .= '<li class="tn-widget__item">'
                .'<a href="'.e($url).'">'.e($post->translatedTitle($locale)).'</a>';

            if ($showDate && $post->published_at !== null) {
                $items .= ' <span class="tn-widget__date">'.e($post->published_at->format('d/m/Y')).'</span>';
            }

            $items .= '</li>';
        }

        if ($items === '') {
            return '';
        }

        $html = '<div class="tn-widget tn-widget--recent-posts">';

        if ($title !== '') {
            $html .= '<h2 class="tn-widget__title">'.e($title).'</h2>';
        }

        return $html.'<ul class="tn-widget__list">'.$items.'</ul></div>';
    }

    private function clampLimit(mixed $value): int
    {
        $limit = (int) $value;

        if ($limit < self::LIMIT_MIN) {
            return self::LIMIT_MIN;
        }

        return min($limit, self::LIMIT_MAX);
    }
}
