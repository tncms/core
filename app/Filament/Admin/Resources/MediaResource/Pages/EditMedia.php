<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MediaResource\Pages;

use App\Filament\Admin\Resources\MediaResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Services\MediaManager;

class EditMedia extends EditRecord
{
    protected static string $resource = MediaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_alt')
                ->label(tn_trans('Generate alt from filename'))
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->action(function (): void {
                    /** @var Media $record */
                    $record = $this->getRecord();

                    if (filled($record->alt)) {
                        Notification::make()
                            ->title(tn_trans('Alt text is already set'))
                            ->body(tn_trans('Clear it first if you want to regenerate it from the filename.'))
                            ->warning()
                            ->send();

                        return;
                    }

                    $alt = Media::humanizeFilename($record->original_filename ?? $record->filename);

                    if ($alt === '') {
                        Notification::make()
                            ->title(tn_trans('Could not derive alt text from the filename'))
                            ->warning()
                            ->send();

                        return;
                    }

                    $record->forceFill(['alt' => $alt])->save();
                    $this->refreshFormData(['alt']);

                    Notification::make()
                        ->title(tn_trans('Alt text generated from filename'))
                        ->success()
                        ->send();
                }),

            Action::make('clear_metadata')
                ->label(tn_trans('Clear metadata'))
                ->icon('heroicon-o-backspace')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(tn_trans('Clear Alt text, Title, Caption and Description for this media item. The file itself is not affected.'))
                ->action(function (): void {
                    /** @var Media $record */
                    $record = $this->getRecord();

                    $record->forceFill([
                        'alt' => null,
                        'title' => null,
                        'caption' => null,
                        'description' => null,
                    ])->save();

                    $this->refreshFormData(['alt', 'title', 'caption', 'description']);

                    Notification::make()
                        ->title(tn_trans('Metadata cleared'))
                        ->success()
                        ->send();
                }),

            DeleteAction::make()
                ->after(function (Media $record): void {
                    /** @var MediaManager $manager */
                    $manager = app('cms.media');
                    $manager->delete($record);
                }),
        ];
    }

    /**
     * Only the editable metadata fields are persisted. The file itself
     * cannot be replaced from this page.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [
            'alt' => $data['alt'] ?? null,
            'title' => $data['title'] ?? null,
            'caption' => $data['caption'] ?? null,
            'description' => $data['description'] ?? null,
        ];
    }
}
