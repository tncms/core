<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MediaResource\Pages;

use App\Filament\Admin\Resources\MediaResource;
use App\Filament\Admin\Pages\MediaUpload;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListMedia extends ListRecords
{
    protected static string $resource = MediaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label(tn_trans('Upload Files'))
                ->icon('heroicon-o-arrow-up-tray')
                ->url(MediaUpload::getUrl()),
        ];
    }
}
