<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LanguageResource\Pages;

use App\Filament\Admin\Resources\LanguageResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLanguages extends ListRecords
{
    protected static string $resource = LanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('syncTranslationFiles')
                ->label(tn_trans('Sync Translation Files'))
                ->icon('heroicon-o-language')
                ->color('gray')
                ->visible(fn (): bool => cms_can('languages.manage'))
                ->requiresConfirmation()
                ->modalHeading(tn_trans('Sync translation files'))
                ->modalDescription(tn_trans('Create missing {locale}.json files for the CMS core, the active theme, and active plugins across all active languages. Existing files are never overwritten.'))
                ->action(function (): void {
                    $summary = app('cms.extension_translation')->syncTranslationFiles();

                    Notification::make()
                        ->title(tn_trans('Translation files synced'))
                        ->body(tn_trans(':created created, :skipped skipped, :errors errors.', [
                            'created' => count($summary['created']),
                            'skipped' => count($summary['skipped']),
                            'errors' => count($summary['errors']),
                        ]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
