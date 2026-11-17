<?php

namespace App\Filament\Resources\Catalog\Substrates;

use App\Enums\EdgeStyle;
use App\Filament\Resources\Catalog\Substrates\Pages\ManageSubstrates;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Filament\Support\MoneyInput;
use App\Models\Substrate;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What the artwork is made on. Drives compatibility rules (glazing, mat, frame) in spec §4.3.
 */
class SubstrateResource extends MasterResource
{
    protected static ?string $model = Substrate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_CATALOG;

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('e.g. Stretched canvas 38 mm')
                ->columnSpanFull(),

            Section::make('Construction')
                ->columns(2)
                ->schema([
                    MasterForm::mm('depth_mm', 'Depth')->default(0)
                        ->helperText('Side depth, e.g. 20 or 38 mm for gallery wrap; 0 for paper.')
                        ->live(onBlur: true),
                    CheckboxList::make('edge_styles')
                        ->label('Edge styles offered')
                        ->options(EdgeStyle::class)
                        ->visible(fn (Get $get): bool => (int) $get('depth_mm') > 0),
                    MasterForm::mm('max_width_mm', 'Max width'),
                    MasterForm::mm('max_height_mm', 'Max height'),
                ]),

            Section::make('Rules')
                ->columns(3)
                ->schema([
                    Toggle::make('allows_glazing')->label('Can have glass / acrylic')->default(true),
                    Toggle::make('allows_mat')->label('Can have a mat')->default(true),
                    Toggle::make('requires_frame')->label('Must be framed'),
                ]),

            Section::make('Cost')
                ->columns(2)
                ->schema([
                    MoneyInput::make('cost_per_m2')->label('Cost per m²')->required()->default(0),
                    MoneyInput::make('cost_per_perimeter_m')->label('Cost per perimeter metre')->default(0)
                        ->helperText('e.g. stretcher bars.'),
                ]),

            Textarea::make('description')->rows(2)->columnSpanFull(),
            ...MasterForm::status(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            MasterTable::mm('depth_mm', 'Depth'),
            IconColumn::make('allows_glazing')->label('Glazing')->boolean(),
            IconColumn::make('allows_mat')->label('Mat')->boolean(),
            IconColumn::make('requires_frame')->label('Needs frame')->boolean(),
            MasterTable::money('cost_per_m2', 'Cost / m²'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSubstrates::route('/'),
        ];
    }
}
