<?php

namespace App\Filament\Resources\Framing\QualityTiers;

use App\Filament\Resources\Framing\QualityTiers\Pages\ManageQualityTiers;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Models\QualityTier;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Standard / Premium / Museum: price factor + the defaults pre-filled in the enquiry form.
 */
class QualityTierResource extends MasterResource
{
    protected static ?string $model = QualityTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_FRAMING;

    protected static ?string $navigationLabel = 'Quality tiers';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $active = fn (Builder $query) => $query->active()->ordered();

        return $schema->components([
            TextInput::make('name')->required()->maxLength(255)->unique(ignoreRecord: true)
                ->placeholder('e.g. Premium'),
            MasterForm::factor('price_factor')->required(),
            Textarea::make('description')->rows(2)->columnSpanFull()
                ->helperText('Shown to customers when they pick a tier.'),
            Section::make('Defaults for the enquiry form')
                ->description('Customers can still change these.')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('default_medium_id')->label('Medium')->relationship('defaultMedium', 'name', $active)->preload(),
                    Select::make('default_substrate_id')->label('Substrate')->relationship('defaultSubstrate', 'name', $active)->preload(),
                    Select::make('default_glazing_id')->label('Glazing')->relationship('defaultGlazing', 'name', $active)->preload(),
                    Select::make('default_mat_id')->label('Mat')->relationship('defaultMat', 'name', $active)->preload(),
                ]),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            TextColumn::make('price_factor')->label('Factor')->prefix('× ')->alignEnd(),
            TextColumn::make('defaultMedium.name')->label('Medium')->placeholder('—'),
            TextColumn::make('defaultGlazing.name')->label('Glazing')->placeholder('—'),
        ])->modifyQueryUsing(fn ($query) => $query->with(['defaultMedium', 'defaultGlazing']));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageQualityTiers::route('/'),
        ];
    }
}
