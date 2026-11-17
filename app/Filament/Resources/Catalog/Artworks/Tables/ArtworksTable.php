<?php

namespace App\Filament\Resources\Catalog\Artworks\Tables;

use App\Enums\ArtworkType;
use App\Filament\Support\MasterTable;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ArtworksTable
{
    public static function configure(Table $table): Table
    {
        return MasterTable::configure($table, [
            SpatieMediaLibraryImageColumn::make('main_image')
                ->label('')
                ->collection('main')
                ->conversion('thumb')
                ->square()
                ->imageSize(48),
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('title')->searchable()->wrap(),
            TextColumn::make('artist.name')->label('Artist')->placeholder('—')->sortable(),
            TextColumn::make('type')->badge(),
            TextColumn::make('pricing_model')->label('Pricing'),
            IconColumn::make('is_featured')->label('Featured')->boolean(),
        ], [
            SelectFilter::make('type')->options(ArtworkType::class),
            SelectFilter::make('artist_id')->label('Artist')->relationship('artist', 'name')->searchable()->preload(),
            SelectFilter::make('categories')->relationship('categories', 'name')->multiple()->preload(),
            TernaryFilter::make('is_featured')->label('Featured'),
        ])->modifyQueryUsing(fn ($query) => $query->with('artist'));
    }
}
