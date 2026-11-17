<?php

namespace App\Filament\Resources\Framing\QualityTiers\Pages;

use App\Filament\Resources\Framing\QualityTiers\QualityTierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageQualityTiers extends ManageRecords
{
    protected static string $resource = QualityTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
