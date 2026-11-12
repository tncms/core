<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Concerns;

use Closure;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * The "Slug" column shared by the Post, Page, Category and Tag lists
 * (CORE-40-H-Z1-M5, WCAG 2.4.4 / 4.1.2).
 *
 * The list shows the slug in the content editing language. A row with no
 * translation in that language has no slug there. Filament wraps every cell in
 * the row link, so that cell used to be a focusable link with no text. Here it
 * is plain, non-interactive text ("Not translated"); the row stays reachable
 * through its named title link and its Edit action. A translated row keeps its
 * slug as the row link. No slug from another language is substituted.
 */
final class TranslatedSlugColumn
{
    /** @param  Closure(Model): string  $slug  the slug in the editing language, '' when untranslated */
    public static function make(Closure $slug): TextColumn
    {
        $isUntranslated = static fn (?Model $record): bool => $record !== null && $slug($record) === '';

        return TextColumn::make('slug')
            ->label(tn_trans('Slug'))
            ->state(static fn (Model $record): string => $slug($record))
            ->placeholder(tn_trans('Not translated'))
            ->disabledClick($isUntranslated)
            // The A6 placeholder contrast rule (resources/views/filament/admin/a11y-overrides.blade.php).
            ->extraCellAttributes(['class' => 'tn-admin-placeholder'])
            ->toggleable();
    }
}
