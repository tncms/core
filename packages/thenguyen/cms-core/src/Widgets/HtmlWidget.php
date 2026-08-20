<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Widgets;

use TheNguyen\CMS\Services\HtmlSanitizer;
use TheNguyen\CMS\Widgets\Fields\RichEditorField;

/**
 * A raw-HTML widget. The stored HTML is always run through the CMS
 * {@see HtmlSanitizer} before output, so unsafe markup (scripts, event
 * handlers, javascript: URLs, …) is stripped at render time.
 */
class HtmlWidget extends Widget
{
    public static function type(): string
    {
        return 'html';
    }

    public static function name(): string
    {
        return core_trans('HTML');
    }

    public static function description(): string
    {
        return core_trans('Custom HTML, sanitized before output.');
    }

    public static function icon(): ?string
    {
        return 'heroicon-o-code-bracket';
    }

    public static function group(): string
    {
        return 'Basic';
    }

    public static function schema(): array
    {
        return [
            RichEditorField::make('html')->label(core_trans('HTML'))->localized()->default(''),
        ];
    }

    public function render(array $settings = [], ?string $locale = null): string
    {
        $title = trim((string) ($settings['title'] ?? ''));
        $rawHtml = (string) ($settings['html'] ?? '');

        /** @var HtmlSanitizer $sanitizer */
        $sanitizer = app('cms.html');
        $safeHtml = $sanitizer->sanitize($rawHtml);

        if ($title === '' && $safeHtml === '') {
            return '';
        }

        $html = '<div class="tn-widget tn-widget--html">';

        if ($title !== '') {
            $html .= '<h2 class="tn-widget__title">'.e($title).'</h2>';
        }

        if ($safeHtml !== '') {
            $html .= '<div class="tn-widget__body">'.$safeHtml.'</div>';
        }

        return $html.'</div>';
    }
}
