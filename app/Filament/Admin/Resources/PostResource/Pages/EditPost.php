<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PostResource\Pages;

use App\Filament\Admin\Resources\PostResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Filament\Concerns\HasTranslations;
use TheNguyen\CMS\Filament\Concerns\TranslationSwitcher;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ContentManager;

class EditPost extends EditRecord
{
    use HasTranslations;
    use TranslationSwitcher;

    protected static string $resource = PostResource::class;

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

        // Live-load the selected locale's translation; clear translatable
        // fields when it does not exist yet. Non-translatable fields (status,
        // published_at, featured_image, categories, tags) are left untouched.
        $data['locale'] = $locale;
        $data['title'] = $translation?->title ?? '';
        $data['slug'] = $translation?->slug ?? '';
        $data['excerpt'] = $translation?->excerpt ?? '';
        $data['content'] = $translation?->content ?? '';
        $data['meta_title'] = $translation?->meta_title ?? '';
        $data['meta_description'] = $translation?->meta_description ?? '';
        $data['meta_keywords'] = $translation?->meta_keywords ?? '';

        $terms = PostResource::termsForForm($record, $locale);
        $data['category_ids'] = $terms['category_ids'];
        $data['tag_names'] = $terms['tag_names'];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ContentManager $contents */
        $contents = app('cms.content');

        /** @var Content $record */
        $locale = $this->selectedLocale();

        $data['type'] = 'post';
        $data['locale'] = $locale;

        // Preserve assignments the editing locale cannot see, so a strict-locale
        // save does not drop a foreign-locale term during the full term sync.
        $preserved = PostResource::termsForForm($record, $locale)['preserved'];
        $data = PostResource::mergeTermIds($data, $preserved);

        return $contents->update($record, $data);
    }
}
