<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource;

class CreateProfileField extends CreateRecord
{
    protected static string $resource = ProfileFieldResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app('cms.user_profile_fields.admin')->createField($data, auth()->user());
    }
}
