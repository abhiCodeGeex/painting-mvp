<?php

namespace App\Filament\Resources\Framing\Glazings\Pages;

use App\Filament\Resources\Framing\Glazings\GlazingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGlazings extends ManageRecords
{
    protected static string $resource = GlazingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
