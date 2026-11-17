<?php

namespace App\Filament\Resources\Catalog\Artworks\Pages;

use App\Filament\Resources\Catalog\Artworks\ArtworkResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditArtwork extends EditRecord
{
    protected static string $resource = ArtworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Archive'),
            RestoreAction::make(),
        ];
    }
}
