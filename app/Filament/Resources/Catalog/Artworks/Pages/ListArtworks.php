<?php

namespace App\Filament\Resources\Catalog\Artworks\Pages;

use App\Filament\Resources\Catalog\Artworks\ArtworkResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class ListArtworks extends ListRecords
{
    protected static string $resource = ArtworkResource::class;

    /**
     * "grid" shows picture cards, "list" the compact table. Kept in the URL so it survives reloads.
     */
    #[Url(as: 'view')]
    public string $display = 'grid';

    public function isGrid(): bool
    {
        return $this->display !== 'list';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleDisplay')
                ->label(fn () => $this->isGrid() ? 'List view' : 'Grid view')
                ->icon(fn () => $this->isGrid() ? Heroicon::OutlinedListBullet : Heroicon::OutlinedSquares2x2)
                ->color('gray')
                ->url(fn () => static::getUrl(['view' => $this->isGrid() ? 'list' : 'grid'])),
            CreateAction::make(),
        ];
    }
}
