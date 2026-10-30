<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PageResource\Pages;

use App\Filament\Admin\Resources\Concerns\WritesContentThroughAuthority;
use App\Filament\Admin\Resources\PageResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\Services\ContentManager;

class CreatePage extends CreateRecord
{
    use WritesContentThroughAuthority;

    protected static string $resource = PageResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var ContentManager $contents */
        $contents = app('cms.content');

        $data['type'] = 'page';
        $data['locale'] = $data['locale'] ?? app('cms.language')->defaultCode();

        // The Classic Editor field dehydrates a cleared editor as null, but a
        // null body means "not provided" to ContentManager (its null-merge
        // keeps the existing translation body). When the form submits the
        // content field, an explicit empty save persists empty instead of
        // resurrecting the old body (CORE-EDITOR-1B); when a schema hook
        // removed the field it is absent and the stored body is preserved.
        if (array_key_exists('content', $data)) {
            $data['content'] = (string) ($data['content'] ?? '');
        }

        return $this->writeThroughContentAuthority(
            fn (): Model => $contents->create($data, ContentWriteContext::interactive()),
        );
    }
}
