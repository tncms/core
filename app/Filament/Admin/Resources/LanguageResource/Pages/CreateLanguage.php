<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LanguageResource\Pages;

use App\Filament\Admin\Resources\LanguageResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Services\LanguageManager;

class CreateLanguage extends CreateRecord
{
    protected static string $resource = LanguageResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var LanguageManager $languages */
        $languages = app('cms.language');

        // The manager enforces the single-default rule and keeps the default
        // language active.
        return $languages->create($data);
    }

    /**
     * Create empty {locale}.json files for the new language (core + active theme
     * + active plugins) and refresh the localized route cache (B5). Best-effort:
     * a sync/cache failure must never break the create.
     */
    protected function afterCreate(): void
    {
        try {
            $code = $this->record->code ?? null;

            if (is_string($code) && $code !== '') {
                app('cms.extension_translation')->syncTranslationFiles([$code]);
            }
        } catch (\Throwable) {
            // Non-fatal — the "Sync Translation Files" action can be run manually.
        }

        // A new active language extends the localized route pattern (vi|en|…),
        // which is compiled at route-registration time.
        LanguageResource::flushLanguageRouteCache();
    }
}
