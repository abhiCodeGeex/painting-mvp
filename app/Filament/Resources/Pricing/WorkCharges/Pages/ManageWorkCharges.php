<?php

namespace App\Filament\Resources\Pricing\WorkCharges\Pages;

use App\Filament\Resources\Pricing\WorkCharges\WorkChargeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWorkCharges extends ManageRecords
{
    protected static string $resource = WorkChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
