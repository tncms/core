<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Resources\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Block removing the super-admin role from the last remaining super admin.
     * (Resource::canDelete guards user deletion; this guards role removal on save.)
     */
    protected function beforeSave(): void
    {
        $record = $this->getRecord();

        if (! method_exists($record, 'isSuperAdmin') || ! $record->isSuperAdmin()) {
            return;
        }

        if (app('cms.permission')->superAdminUserCount() > 1) {
            return;
        }

        $superAdminRoleId = UserResource::superAdminRoleId();
        $selectedRoleIds = array_map('intval', (array) data_get($this->data, 'roles', []));

        if ($superAdminRoleId !== null && ! in_array($superAdminRoleId, $selectedRoleIds, true)) {
            Notification::make()
                ->title(tn_trans('Cannot remove the last super admin'))
                ->body(tn_trans('Assign the super-admin role to another user before removing it here.'))
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
