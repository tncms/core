<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Pages\Page;

/**
 * Plugins → Plugin Editor (placeholder, v1.0.0-beta.1).
 *
 * Plugin file editing is not implemented in this phase — this page only
 * announces the planned, security-sensitive capability.
 */
class PluginEditorPage extends Page
{
    protected static ?string $slug = 'plugins/editor';

    protected static ?string $navigationLabel = 'Plugin Editor';

    protected static string|\UnitEnum|null $navigationGroup = 'Plugins';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-code-bracket';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Plugin Editor';

    protected string $view = 'filament.admin.pages.plugin-editor-page';

    public static function getNavigationLabel(): string
    {
        return tn_trans('Plugin Editor');
    }

    public function getTitle(): string
    {
        return tn_trans('Plugin Editor');
    }
}
