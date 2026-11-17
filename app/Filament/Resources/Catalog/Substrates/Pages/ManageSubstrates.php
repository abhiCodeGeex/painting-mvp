<?php

namespace App\Filament\Resources\Catalog\Substrates\Pages;

use App\Filament\Resources\Catalog\Substrates\SubstrateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSubstrates extends ManageRecords
{
    protected static string $resource = SubstrateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
