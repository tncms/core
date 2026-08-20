<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets;

use TheNguyen\CMS\Widgets\Fields\TextareaField;

/**
 * A simple text widget: an optional title + a paragraph-safe block of plain
 * text. The text is escaped and newline-aware — it never renders raw HTML.
 */
class TextWidget extends Widget
{
    public static function type(): string
    {
        return 'text';
    }

    public static function name(): string
    {
        return core_trans('Text');
    }

    public static function description(): string
    {
        return core_trans('A block of plain, escaped text.');
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-bars-3-bottom-left';
    }

    public static function group(): string
    {
        return 'Basic';
    }

    public static function schema(): array
    {
        return [
            TextareaField::make('text')->label(core_trans('Text'))->localized()->default(''),
        ];
    }

    public function render(array $settings = [], ?string $locale = null): string
    {
        $title = trim((string) ($settings['title'] ?? ''));
        $text = trim((string) ($settings['text'] ?? ''));

        if ($title === '' && $text === '') {
            return '';
        }

        $html = '<div class="tn-widget tn-widget--text">';

        if ($title !== '') {
            $html .= '<h2 class="tn-widget__title">'.e($title).'</h2>';
        }

        if ($text !== '') {
            $html .= '<div class="tn-widget__body">'.nl2br(e($text)).'</div>';
        }

        return $html.'</div>';
    }
}
