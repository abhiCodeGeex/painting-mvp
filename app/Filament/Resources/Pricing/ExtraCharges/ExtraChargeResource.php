<?php

namespace App\Filament\Resources\Pricing\ExtraCharges;

use App\Enums\ExtraChargeBasis;
use App\Enums\ExtraChargeType;
use App\Filament\Resources\Pricing\ExtraCharges\Pages\ManageExtraCharges;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Filament\Support\MoneyInput;
use App\Models\ExtraCharge;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Packing, shipping, installation… added as extra lines on a quote.
 */
class ExtraChargeResource extends MasterResource
{
    protected static ?string $model = ExtraCharge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_PRICING;

    protected static ?string $navigationLabel = 'Extra charges';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('e.g. Wooden crate')
                ->columnSpanFull(),
            Select::make('type')
                ->options(ExtraChargeType::class)
                ->required()
                ->native(false),
            Select::make('pricing_type')
                ->label('Charged')
                ->options(ExtraChargeBasis::class)
                ->required()
                ->native(false),
            MoneyInput::make('cost')->label('Cost')->required(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            TextColumn::make('type')->badge(),
            TextColumn::make('pricing_type')->label('Charged'),
            MasterTable::money('cost', 'Cost'),
        ], [
            SelectFilter::make('type')->options(ExtraChargeType::class),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExtraCharges::route('/'),
        ];
    }
}
