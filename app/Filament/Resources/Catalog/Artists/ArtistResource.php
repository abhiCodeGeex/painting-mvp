<?php

namespace App\Filament\Resources\Catalog\Artists;

use App\Filament\Resources\Catalog\Artists\Pages\ManageArtists;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Models\Artist;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ArtistResource extends MasterResource
{
    protected static ?string $model = Artist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_CATALOG;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            SpatieMediaLibraryFileUpload::make('photo')
                ->collection('photo')
                ->image()
                ->avatar()
                ->imageEditor()
                ->maxSize(5120)
                ->columnSpanFull(),
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('country')
                ->label('Country code')
                ->default('IN')
                ->length(2)
                ->alpha()
                ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null)
                ->helperText('Two letters, e.g. IN, IT.'),
            Textarea::make('bio')->rows(4)->columnSpanFull(),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            SpatieMediaLibraryImageColumn::make('photo')->collection('photo')->conversion('thumb')->circular()->label(''),
            TextColumn::make('name')->searchable(),
            TextColumn::make('country'),
            TextColumn::make('artworks_count')->counts('artworks')->label('Artworks')->alignEnd(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageArtists::route('/'),
        ];
    }
}
