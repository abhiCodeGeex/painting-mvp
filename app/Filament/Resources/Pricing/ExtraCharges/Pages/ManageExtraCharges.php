<?php

namespace App\Filament\Resources\Pricing\ExtraCharges\Pages;

use App\Filament\Resources\Pricing\ExtraCharges\ExtraChargeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExtraCharges extends ManageRecords
{
    protected static string $resource = ExtraChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
