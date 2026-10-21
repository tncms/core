<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldGroupResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldGroupResource;

class CreateProfileFieldGroup extends CreateRecord
{
    protected static string $resource = ProfileFieldGroupResource::class;

    /** Route the write through the audited admin service, not a raw model save. */
    protected function handleRecordCreation(array $data): Model
    {
        return app('cms.user_profile_fields.admin')->createGroup($data, auth()->user());
    }
}
