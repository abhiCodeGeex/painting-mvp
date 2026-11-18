<?php

namespace App\Filament\Resources\Catalog\Artworks;

use App\Filament\Resources\Catalog\Artworks\Pages\CreateArtwork;
use App\Filament\Resources\Catalog\Artworks\Pages\EditArtwork;
use App\Filament\Resources\Catalog\Artworks\Pages\ListArtworks;
use App\Filament\Resources\Catalog\Artworks\Schemas\ArtworkForm;
use App\Filament\Resources\Catalog\Artworks\Tables\ArtworksTable;
use App\Filament\Support\MasterResource;
use App\Models\Artwork;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ArtworkResource extends MasterResource
{
    protected static ?string $model = Artwork::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_CATALOG;

    protected static ?string $navigationLabel = 'Artworks';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['code', 'title'];
    }

    public static function form(Schema $schema): Schema
    {
        return ArtworkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ArtworksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArtworks::route('/'),
            'create' => CreateArtwork::route('/create'),
            'edit' => EditArtwork::route('/{record}/edit'),
        ];
    }
}
