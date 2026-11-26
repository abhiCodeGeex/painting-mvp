<?php

namespace App\Filament\Resources\Pricing\WorkCharges;

use App\Filament\Resources\Pricing\WorkCharges\Pages\ManageWorkCharges;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Filament\Support\MoneyInput;
use App\Models\WorkCharge;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Work after the artwork: cutting, mounting, installation… The amount is the default per piece;
 * staff pick the work on a quote line and can change the amount there.
 */
class WorkChargeResource extends MasterResource
{
    protected static ?string $model = WorkCharge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_PRICING;

    protected static ?string $navigationLabel = 'Work charges';

    protected static ?int $navigationSort = 25;

    /** Not used on quotes any more (labour per piece covers it): kept for its data, hidden from the menu. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('e.g. Installation'),
            MoneyInput::make('amount')->label('Default amount (per piece)')->required()
                ->helperText('Filled in on the quote; staff can change it there.'),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            MasterTable::money('amount', 'Default per piece'),
            TextColumn::make('description')->limit(60)->toggleable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWorkCharges::route('/'),
        ];
    }
}
