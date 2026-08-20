<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PageResource\Pages;

use App\Filament\Admin\Resources\PageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Filament\Concerns\HasTranslations;
use TheNguyen\CMS\Filament\Concerns\TranslationSwitcher;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ContentManager;

class EditPage extends EditRecord
{
    use HasTranslations;
    use TranslationSwitcher;

    protected static string $resource = PageResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->translationSwitcher(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Content $record */
        $record = $this->record;

        $locale = $this->selectedLocale();
        $translation = $record->translations()->where('locale', $locale)->first();

        // Live-load the selected locale's translation; clear the translatable
        // fields when it does not exist yet so a new translation can be created
        // without inheriting another locale's content.
        $data['locale'] = $locale;
        $data['title'] = $translation?->title ?? '';
        $data['slug'] = $translation?->slug ?? '';
        $data['excerpt'] = $translation?->excerpt ?? '';
        $data['content'] = $translation?->content ?? '';
        $data['meta_title'] = $translation?->meta_title ?? '';
        $data['meta_description'] = $translation?->meta_description ?? '';
        $data['meta_keywords'] = $translation?->meta_keywords ?? '';

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ContentManager $contents */
        $contents = app('cms.content');

        $data['type'] = 'page';
        $data['locale'] = $this->selectedLocale();

        /** @var Content $record */
        return $contents->update($record, $data);
    }
}
