<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TagResource\Pages;

use App\Filament\Admin\Resources\TagResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Filament\Concerns\HasTranslations;
use TheNguyen\CMS\Filament\Concerns\TranslationSwitcher;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\TaxonomyManager;

class EditTag extends EditRecord
{
    use HasTranslations;
    use TranslationSwitcher;

    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->translationSwitcher(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Term $record */
        $record = $this->record;
        $locale = $this->selectedLocale();
        $translation = $record->translations()->where('locale', $locale)->first();

        // Live-load the selected locale's translation; clear when missing.
        $data['locale'] = $locale;
        $data['name'] = $translation?->name ?? '';
        $data['slug'] = $translation?->slug ?? '';
        $data['description'] = $translation?->description ?? '';
        $data['meta_title'] = $translation?->meta_title ?? '';
        $data['meta_description'] = $translation?->meta_description ?? '';

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var TaxonomyManager $taxonomies */
        $taxonomies = app('cms.taxonomy');

        $data['locale'] = $this->selectedLocale();

        /** @var Term $record */
        return $taxonomies->updateTerm($record, $data);
    }
}
