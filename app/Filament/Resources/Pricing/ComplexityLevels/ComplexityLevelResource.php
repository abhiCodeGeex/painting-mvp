<?php

namespace App\Filament\Resources\Pricing\ComplexityLevels;

use App\Filament\Resources\Pricing\ComplexityLevels\Pages\ManageComplexityLevels;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Filament\Support\MoneyInput;
use App\Models\ComplexityLevel;
use App\Providers\Filament\AdminPanelProvider;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Complexity of a custom creation (Simple / Medium / Complex) as a price factor.
 */
class ComplexityLevelResource extends MasterResource
{
    protected static ?string $model = ComplexityLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_PRICING;

    protected static ?string $navigationLabel = 'Complexity levels';

    /** Complexity no longer affects prices (new creations are priced by the chosen artist's fee). */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('e.g. Complex'),
            MasterForm::factor('price_factor')->required()
                ->helperText('Multiplies the base price of a custom creation. Sales can still override on the quote.'),
            MoneyInput::make('artist_rate_per_m2')->label('Artist labour per m²')->default(0)->required()->suffix('/ m²')
                ->helperText('What we pay the artist per m² of painting (a cost). It is marked up like materials and shown as "Artist labour" on the quote.'),
            MoneyInput::make('artist_minimum')->label('Minimum artist labour per piece')->default(0)->required()
                ->helperText('Small paintings still take time: labour is never less than this.'),
            Textarea::make('description')->rows(2)
                ->placeholder('e.g. Detailed figurative work, many elements'),
            ...MasterForm::status(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            TextColumn::make('price_factor')->label('Factor')->prefix('× ')->alignEnd(),
            TextColumn::make('artist_rate_per_m2')->label('Artist labour / m²')->formatStateUsing(fn ($state) => Money::format((int) $state))->alignEnd(),
            TextColumn::make('artist_minimum')->label('Min. per piece')->formatStateUsing(fn ($state) => Money::format((int) $state))->alignEnd()->toggleable(),
            TextColumn::make('description')->limit(60)->placeholder('—'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageComplexityLevels::route('/'),
        ];
    }
}
