<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldGroupResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Exceptions\InvalidProfileFieldDefinitionException;
use TheNguyen\CMS\Exceptions\ProfileFieldFrozenException;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldGroupResource;
use TheNguyen\CMS\Models\UserProfileFieldTranslation;

class EditProfileFieldGroup extends EditRecord
{
    protected static string $resource = ProfileFieldGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // F1: only offered when the group is admin-owned AND empty — i.e.
                // when deletion would actually succeed. Locked or non-empty groups
                // hide the action; the service still fails closed if bypassed.
                ->visible(fn (Model $record): bool => ProfileFieldGroupResource::canDelete($record)
                    && ! $record->fields()->exists())
                ->using(function (Model $record): void {
                    try {
                        app('cms.user_profile_fields.admin')->deleteGroup($record, auth()->user());
                    } catch (ProfileFieldFrozenException|InvalidProfileFieldDefinitionException|AuthorizationException) {
                        Notification::make()->danger()
                            ->title(tn_trans('This group cannot be deleted.'))
                            ->body(tn_trans('A group can only be deleted when it is admin-owned and has no fields.'))
                            ->send();

                        throw new Halt;
                    }

                    Notification::make()->success()->title(tn_trans('Profile field group deleted.'))->send();
                }),
        ];
    }

    /** Hydrate localized label fields from the translations table. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['label'] = $this->translationLabel($data['id'] ?? 0, default_locale());
        $data['label_vi'] = $this->translationLabel($data['id'] ?? 0, 'vi');

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app('cms.user_profile_fields.admin')->updateGroup($record, $data, auth()->user());
    }

    private function translationLabel(int $ownerId, string $locale): ?string
    {
        return UserProfileFieldTranslation::query()
            ->where('owner_type', 'group')->where('owner_id', $ownerId)->where('locale', $locale)
            ->value('label');
    }
}
