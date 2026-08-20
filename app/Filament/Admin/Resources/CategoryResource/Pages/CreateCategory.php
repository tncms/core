<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CategoryResource\Pages;

use App\Filament\Admin\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use TheNguyen\CMS\Services\TaxonomyManager;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var TaxonomyManager $taxonomies */
        $taxonomies = app('cms.taxonomy');

        $data['locale'] = $data['locale'] ?? app('cms.language')->defaultCode();

        return $taxonomies->createTerm('category', $data);
    }
}
