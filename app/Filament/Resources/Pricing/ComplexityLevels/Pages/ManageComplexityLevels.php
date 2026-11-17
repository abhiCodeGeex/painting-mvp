<?php

namespace App\Filament\Resources\Pricing\ComplexityLevels\Pages;

use App\Filament\Resources\Pricing\ComplexityLevels\ComplexityLevelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageComplexityLevels extends ManageRecords
{
    protected static string $resource = ComplexityLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
