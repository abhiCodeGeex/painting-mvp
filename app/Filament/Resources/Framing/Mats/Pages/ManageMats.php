<?php

namespace App\Filament\Resources\Framing\Mats\Pages;

use App\Filament\Resources\Framing\Mats\MatResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMats extends ManageRecords
{
    protected static string $resource = MatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
