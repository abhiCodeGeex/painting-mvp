<?php

namespace App\Filament\Resources\Lists\EnquirySources;

use App\Filament\Resources\Lists\EnquirySources\Pages\ManageEnquirySources;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Models\EnquirySource;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class EnquirySourceResource extends MasterResource
{
    protected static ?string $model = EnquirySource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_LISTS;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('e.g. Website, Phone, Showroom walk-in'),
            ...MasterForm::status(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEnquirySources::route('/'),
        ];
    }
}
