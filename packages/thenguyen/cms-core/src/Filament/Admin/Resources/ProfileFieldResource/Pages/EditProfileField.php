<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Exceptions\ProfileFieldFrozenException;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource;
use TheNguyen\CMS\Models\UserProfileFieldTranslation;

class EditProfileField extends EditRecord
{
    protected static string $resource = ProfileFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // F1: only offered when the definition is administratively
                // deletable (admin-owned). Locked Core/plugin fields hide the
                // action; the service below still fails closed if bypassed.
                ->visible(fn (Model $record): bool => ProfileFieldResource::canDelete($record))
                // F2: preflight — show the real count of stored values this delete
                // cascades (value rows across all locales), singular/plural correct,
                // before an irreversible confirmation.
                ->modalDescription(function (Model $record): string {
                    $count = app('cms.user_profile_fields.admin')->impactedValueCount($record);

                    return tn_trans(
                        $count === 1
                            ? 'Deleting this field will permanently remove :count stored value. This cannot be undone.'
                            : 'Deleting this field will permanently remove :count stored values. This cannot be undone.',
                        ['count' => $count],
                    );
                })
                ->using(function (Model $record): void {
                    try {
                        app('cms.user_profile_fields.admin')->deleteField($record, auth()->user());
                    } catch (ProfileFieldFrozenException|AuthorizationException) {
                        // Expected, controlled denial for a forged/stale request —
                        // never a generic 500. The server guard already refused.
                        Notification::make()->danger()
                            ->title(tn_trans('This field cannot be deleted.'))
                            ->body(tn_trans('Core/plugin-owned fields are managed by their owner.'))
                            ->send();

                        throw new Halt;
                    }

                    Notification::make()->success()->title(tn_trans('Profile field deleted.'))->send();
                }),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $id = (int) ($data['id'] ?? 0);
        $data['label'] = $this->tr($id, default_locale(), 'label');
        $data['help'] = $this->tr($id, default_locale(), 'help');
        $data['label_vi'] = $this->tr($id, 'vi', 'label');
        $data['help_vi'] = $this->tr($id, 'vi', 'help');

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app('cms.user_profile_fields.admin')->updateField($record, $data, auth()->user());
    }

    private function tr(int $ownerId, string $locale, string $col): ?string
    {
        return UserProfileFieldTranslation::query()
            ->where('owner_type', 'field')->where('owner_id', $ownerId)->where('locale', $locale)
            ->value($col);
    }
}
