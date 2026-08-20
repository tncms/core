<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LanguageResource\Pages;

use App\Filament\Admin\Resources\LanguageResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Models\Language;
use TheNguyen\CMS\Services\LanguageManager;

class EditLanguage extends EditRecord
{
    protected static string $resource = LanguageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The default language cannot be deleted. Safe-delete (B3) + route
            // cache refresh (B5) are handled by the shared resource helper.
            DeleteAction::make()
                ->hidden(fn (Language $record): bool => $record->is_default)
                ->action(fn (Language $record) => LanguageResource::deleteLanguageSafely($record))
                ->successRedirectUrl(LanguageResource::getUrl('index')),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var LanguageManager $languages */
        $languages = app('cms.language');

        /** @var Language $record */
        return $languages->update($record, $data);
    }

    /**
     * A language edit may flip is_active / is_default, both of which feed the
     * localized route pattern, so refresh the route cache after saving (B5).
     */
    protected function afterSave(): void
    {
        LanguageResource::flushLanguageRouteCache();
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(tn_trans('Language saved'))
            ->body(tn_trans('Route cache was cleared so localized URLs can update.'));
    }
}
