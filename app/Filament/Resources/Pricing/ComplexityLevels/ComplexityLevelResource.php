<?php

namespace App\Filament\Resources\Pricing\ComplexityLevels;

use App\Filament\Resources\Pricing\ComplexityLevels\Pages\ManageComplexityLevels;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Models\ComplexityLevel;
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
 * Complexity of a custom creation (Simple / Medium / Complex) as a price factor.
 */
class ComplexityLevelResource extends MasterResource
{
    protected static ?string $model = ComplexityLevel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_PRICING;

    protected static ?string $navigationLabel = 'Complexity levels';

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
