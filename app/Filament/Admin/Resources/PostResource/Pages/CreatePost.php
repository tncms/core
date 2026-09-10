<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PostResource\Pages;

use App\Filament\Admin\Resources\PostResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Services\ContentManager;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var ContentManager $contents */
        $contents = app('cms.content');

        $data['type'] = 'post';
        $data['locale'] = $data['locale'] ?? app('cms.language')->defaultCode();

        // The Classic Editor field dehydrates a cleared editor as null, but a
        // null body means "not provided" to ContentManager (its null-merge
        // keeps the existing translation body). The form always submits the
        // content field, so an explicit empty save must persist empty instead
        // of resurrecting the old body (CORE-EDITOR-1B).
        $data['content'] = (string) ($data['content'] ?? '');
        $data = PostResource::mergeTermIds($data);

        return $contents->create($data);
    }
}
