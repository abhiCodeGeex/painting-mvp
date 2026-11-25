<?php

namespace App\Filament\Resources\Framing\Glazings;

use App\Enums\GlazingMaterial;
use App\Enums\ReflectionLevel;
use App\Filament\Resources\Framing\Glazings\Pages\ManageGlazings;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\StockColumns;
use App\Models\Glazing;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * None, float glass, acrylic, UV, museum, anti-reflective…
 */
class GlazingResource extends MasterResource
{
    protected static ?string $model = Glazing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_FRAMING;

    protected static ?string $navigationLabel = 'Glazing';

    protected static ?string $modelLabel = 'glazing';

    protected static ?string $pluralModelLabel = 'glazing';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('e.g. UV-protective acrylic')
                ->columnSpanFull(),
            Select::make('material')->options(GlazingMaterial::class)->required()->native(false),
            Select::make('reflection_level')
                ->label('Reflection (3D preview)')
                ->options(ReflectionLevel::class)
                ->required()
                ->native(false),
            MoneyInput::make('cost_per_m2')->label('Cost per m²')->required()->default(0),
            Section::make('Size limits')
                ->description('Larger pieces must use another glazing, e.g. acrylic instead of glass.')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    MasterForm::mm('max_width_mm', 'Max width'),
                    MasterForm::mm('max_height_mm', 'Max height'),
                    TextInput::make('max_area_m2')->label('Max area')->numeric()->step(0.001)->minValue(0)->suffix('m²'),
                ]),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return StockColumns::apply(MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            TextColumn::make('material')->badge(),
            TextColumn::make('reflection_level')->label('Reflection'),
            MasterTable::money('cost_per_m2', 'Cost / m²'),
            TextColumn::make('max_area_m2')->label('Max area')->suffix(' m²')->placeholder('—')->alignEnd(),
            ...StockColumns::columns(),
        ], [
            SelectFilter::make('material')->options(GlazingMaterial::class),
        ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGlazings::route('/'),
        ];
    }
}
