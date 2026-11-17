<?php

namespace App\Filament\Resources\Catalog\StandardSizes\Pages;

use App\Filament\Resources\Catalog\StandardSizes\StandardSizeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStandardSizes extends ManageRecords
{
    protected static string $resource = StandardSizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
