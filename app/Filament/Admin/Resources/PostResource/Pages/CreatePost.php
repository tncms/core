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
        $data = PostResource::mergeTermIds($data);

        return $contents->create($data);
    }
}
