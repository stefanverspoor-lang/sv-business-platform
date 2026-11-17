<?php

namespace App\Filament\Resources\DataImports\Pages;

use App\Filament\Resources\DataImports\DataImportResource;
use Filament\Resources\Pages\ListRecords;

class ListDataImports extends ListRecords
{
    protected static string $resource = DataImportResource::class;
}
