<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MenuResource\Pages;

use App\Filament\Admin\Resources\MenuResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Services\MenuManager;

class CreateMenu extends CreateRecord
{
    protected static string $resource = MenuResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var MenuManager $menus */
        $menus = app('cms.menu');

        $data['locale'] = $data['locale'] ?? app('cms.language')->defaultCode();

        return $menus->createMenu($data);
    }
}
