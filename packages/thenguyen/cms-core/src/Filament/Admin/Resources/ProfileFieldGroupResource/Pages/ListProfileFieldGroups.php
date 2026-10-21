<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldGroupResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldGroupResource;

class ListProfileFieldGroups extends ListRecords
{
    protected static string $resource = ProfileFieldGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
