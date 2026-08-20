<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RoleResource\Pages;

use App\Filament\Admin\Resources\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use TheNguyen\CMS\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (Role $record): bool => $record->is_system),
        ];
    }

    /**
     * Inject the per-group permission checkbox state from the role's pivot.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_merge($data, RoleResource::fillStateFromRole($this->getRecord()));
    }

    protected function afterSave(): void
    {
        RoleResource::syncPermissions($this->getRecord(), $this->data);
    }
}
