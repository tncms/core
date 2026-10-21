<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource;

class ListProfileFields extends ListRecords
{
    protected static string $resource = ProfileFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
