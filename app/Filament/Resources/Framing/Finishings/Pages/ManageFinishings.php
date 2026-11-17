<?php

namespace App\Filament\Resources\Framing\Finishings\Pages;

use App\Filament\Resources\Framing\Finishings\FinishingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFinishings extends ManageRecords
{
    protected static string $resource = FinishingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
