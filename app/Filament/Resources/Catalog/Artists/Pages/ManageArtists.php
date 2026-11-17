<?php

namespace App\Filament\Resources\Catalog\Artists\Pages;

use App\Filament\Resources\Catalog\Artists\ArtistResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageArtists extends ManageRecords
{
    protected static string $resource = ArtistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
