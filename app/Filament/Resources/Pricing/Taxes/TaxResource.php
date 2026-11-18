<?php

namespace App\Filament\Resources\Pricing\Taxes;

use App\Filament\Resources\Pricing\Taxes\Pages\ManageTaxes;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Models\Tax;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * GST rates. Intra-state quotes split the rate into CGST + SGST; inter-state use IGST.
 */
class TaxResource extends MasterResource
{
    protected static ?string $model = Tax::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_PRICING;

    protected static ?string $navigationLabel = 'Taxes (GST)';

    protected static ?string $modelLabel = 'GST rate';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('e.g. GST 18%'),
            MasterForm::percent('rate_percent', 'Total GST rate')
                ->required()
                ->helperText('Full rate. Same-state quotes show half as CGST and half as SGST; other states show it as IGST.'),
            TextInput::make('hsn_sac_code')
                ->label('HSN / SAC code')
                ->maxLength(10),
            Toggle::make('is_default')
                ->label('Default rate for new quotes')
                ->helperText('Only one rate can be the default.'),
            ...MasterForm::status(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            TextColumn::make('rate_percent')->label('Rate')->suffix('%')->alignEnd(),
            TextColumn::make('hsn_sac_code')->label('HSN / SAC')->placeholder('—'),
            IconColumn::make('is_default')->label('Default')->boolean(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTaxes::route('/'),
        ];
    }
}
