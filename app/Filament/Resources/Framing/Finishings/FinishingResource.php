<?php

namespace App\Filament\Resources\Framing\Finishings;

use App\Enums\PricingType;
use App\Filament\Resources\Framing\Finishings\Pages\ManageFinishings;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Filament\Support\MoneyInput;
use App\Models\Finishing;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Finishing & hanging hardware: varnish, D-rings + wire, French cleat, dust cover…
 */
class FinishingResource extends MasterResource
{
    protected static ?string $model = Finishing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_FRAMING;

    protected static ?string $navigationLabel = 'Finishing & hardware';

    protected static ?string $modelLabel = 'finishing option';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('e.g. D-rings + wire')
                ->columnSpanFull(),
            Select::make('pricing_type')->label('Charged')->options(PricingType::class)->required()->native(false),
            MoneyInput::make('cost')->label('Cost')->required()->default(0),
            Toggle::make('is_default')
                ->label('Pre-selected in the enquiry form')
                ->columnSpanFull(),
            CheckboxList::make('substrates')
                ->label('Applies to substrates')
                ->relationship('substrates', 'name', fn (Builder $query) => $query->active()->ordered())
                ->columns(2)
                ->columnSpanFull()
                ->helperText('Leave empty to offer it for every substrate.'),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            TextColumn::make('pricing_type')->label('Charged'),
            MasterTable::money('cost', 'Cost'),
            IconColumn::make('is_default')->label('Default')->boolean(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFinishings::route('/'),
        ];
    }
}
