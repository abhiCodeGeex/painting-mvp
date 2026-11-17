<?php

namespace App\Filament\Resources\Catalog\Artworks\Pages;

use App\Filament\Resources\Catalog\Artworks\ArtworkResource;
use Filament\Resources\Pages\CreateRecord;

class CreateArtwork extends CreateRecord
{
    protected static string $resource = ArtworkResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
