<?php

namespace App\Filament\Resources\Catalog\Mediums\Pages;

use App\Filament\Resources\Catalog\Mediums\MediumResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMediums extends ManageRecords
{
    protected static string $resource = MediumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
