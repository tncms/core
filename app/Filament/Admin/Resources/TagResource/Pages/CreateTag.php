<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TagResource\Pages;

use App\Filament\Admin\Resources\TagResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Services\TaxonomyManager;

class CreateTag extends CreateRecord
{
    protected static string $resource = TagResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var TaxonomyManager $taxonomies */
        $taxonomies = app('cms.taxonomy');

        $data['locale'] = $data['locale'] ?? app('cms.language')->defaultCode();

        return $taxonomies->createTerm('tag', $data);
    }
}
