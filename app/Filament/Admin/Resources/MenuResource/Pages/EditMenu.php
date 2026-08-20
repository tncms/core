<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MenuResource\Pages;

use App\Filament\Admin\Resources\MenuResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Filament\Concerns\HasTranslations;
use TheNguyen\CMS\Filament\Concerns\TranslationSwitcher;
use TheNguyen\CMS\Models\Menu;
use TheNguyen\CMS\Services\MenuManager;

class EditMenu extends EditRecord
{
    use HasTranslations;
    use TranslationSwitcher;

    protected static string $resource = MenuResource::class;

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
        /** @var Menu $record */
        $record = $this->record;

        $locale = $this->selectedLocale();
        $translation = $record->translations()->where('locale', $locale)->first();

        // Live-load the selected locale's translation; clear when missing.
        // Non-translatable fields (slug, location, status, sort_order,
        // is_system) are not touched here.
        $data['locale'] = $locale;
        $data['name'] = $translation?->name ?? '';
        $data['description'] = $translation?->description ?? '';

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MenuManager $menus */
        $menus = app('cms.menu');

        $data['locale'] = $this->selectedLocale();

        /** @var Menu $record */
        return $menus->updateMenu($record, $data);
    }
}
