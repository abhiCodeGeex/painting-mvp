<?php

namespace App\Filament\Resources\Pricing\Taxes\Pages;

use App\Filament\Resources\Pricing\Taxes\TaxResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTaxes extends ManageRecords
{
    protected static string $resource = TaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
