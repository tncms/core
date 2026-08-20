<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaxonomyResource\Pages;

use App\Filament\Admin\Resources\TaxonomyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxonomy extends CreateRecord
{
    protected static string $resource = TaxonomyResource::class;
}
