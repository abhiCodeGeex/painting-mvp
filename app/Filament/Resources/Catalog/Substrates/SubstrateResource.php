<?php

namespace App\Filament\Resources\Catalog\Substrates;

use App\Enums\EdgeStyle;
use App\Enums\PrintingMethod;
use App\Enums\SubstrateFinish;
use App\Enums\SubstrateMaterial;
use App\Enums\SubstrateUnit;
use App\Filament\Resources\Catalog\Substrates\Pages\ManageSubstrates;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\StockColumns;
use App\Models\Substrate;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What the artwork is made on. Drives compatibility rules (glazing, mat, frame) in spec §4.3.
 */
class SubstrateResource extends MasterResource
{
    protected static ?string $model = Substrate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare3Stack3d;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_MATERIALS;

    protected static ?string $navigationLabel = 'Substrates';

    protected static ?int $navigationSort = 10;

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

            Section::make('Specifications')
                ->columns(2)
                ->schema([
                    Select::make('material_type')->label('Material type')->options(SubstrateMaterial::class)->native(false),
                    Select::make('finish')->options(SubstrateFinish::class)->native(false),
                    TextInput::make('thickness_mm')->label('Thickness')->numeric()->minValue(0)->step(0.1)->suffix('mm'),
                    TextInput::make('gsm')->label('GSM')->numeric()->integer()->minValue(0)->helperText('Paper and canvas weight.'),
                    CheckboxList::make('printing_methods')->label('Printing method')->options(PrintingMethod::class)->columns(3),
                    Select::make('unit')->options(SubstrateUnit::class)->native(false)->helperText('How it is bought / stocked.'),
                    TagsInput::make('available_sizes')
                        ->label('Available sizes (inches)')
                        ->placeholder('e.g. 12×18')
                        ->suggestions(['8×10', '12×12', '12×18', '16×20', '18×24', '20×30', '24×36', '30×40', '36×48']),
                    TextInput::make('supplier')->maxLength(255)->placeholder('Supplier name'),
                ]),

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
        return StockColumns::apply(MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            TextColumn::make('material_type')->label('Material')->badge()->placeholder('—'),
            TextColumn::make('thickness_gsm')
                ->label('Thickness / GSM')
                ->state(fn (Substrate $record) => collect([
                    $record->thickness_mm !== null ? rtrim(rtrim((string) $record->thickness_mm, '0'), '.').' mm' : null,
                    $record->gsm ? "{$record->gsm} GSM" : null,
                ])->filter()->implode(', ') ?: null)
                ->placeholder('—'),
            TextColumn::make('finish')->placeholder('—'),
            TextColumn::make('printing_methods')
                ->label('Printing')
                ->badge()
                ->formatStateUsing(fn ($state) => PrintingMethod::tryFrom((string) $state)?->getLabel() ?? $state)
                ->placeholder('—'),
            TextColumn::make('available_sizes')->label('Sizes')->badge()->color('gray')->toggleable()->placeholder('—'),
            TextColumn::make('supplier')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('unit')->placeholder('—')->toggleable(),
            MasterTable::mm('depth_mm', 'Depth')->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('allows_glazing')->label('Glazing')->boolean()->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('allows_mat')->label('Mat')->boolean()->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('requires_frame')->label('Needs frame')->boolean()->toggleable(isToggledHiddenByDefault: true),
            MasterTable::money('cost_per_m2', 'Cost / m²'),
            ...StockColumns::columns(),
        ], [
            SelectFilter::make('material_type')->label('Material type')->options(SubstrateMaterial::class),
            SelectFilter::make('finish')->options(SubstrateFinish::class),
            SelectFilter::make('printing_method')
                ->label('Printing method')
                ->options(PrintingMethod::class)
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->whereJsonContains('printing_methods', $data['value'])
                    : $query),
            SelectFilter::make('unit')->options(SubstrateUnit::class),
        ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSubstrates::route('/'),
        ];
    }
}
