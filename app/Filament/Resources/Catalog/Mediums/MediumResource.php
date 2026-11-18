<?php

namespace App\Filament\Resources\Catalog\Mediums;

use App\Enums\MediumKind;
use App\Filament\Resources\Catalog\Mediums\Pages\ManageMediums;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Models\Medium;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class MediumResource extends MasterResource
{
    protected static ?string $model = Medium::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_MATERIALS;

    protected static ?string $navigationLabel = 'Mediums';

    protected static ?int $navigationSort = 20;

    // Laravel pluralises "medium" to "media"; keep readable labels and URL.
    protected static ?string $slug = 'catalog/mediums';

    protected static ?string $modelLabel = 'medium';

    protected static ?string $pluralModelLabel = 'mediums';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('e.g. Oil on canvas, Giclée print'),
            Select::make('kind')
                ->options(MediumKind::class)
                ->required()
                ->native(false)
                ->live(),
            MasterForm::factor('price_factor')->required(),
            TextInput::make('min_resolution_ppi')
                ->label('Minimum print resolution')
                ->integer()
                ->minValue(50)
                ->suffix('ppi')
                ->helperText('Customer uploads below this get a quality warning.')
                ->visible(fn (Get $get): bool => self::isPrint($get('kind'))),
            CheckboxList::make('substrates')
                ->label('Allowed substrates')
                ->relationship('substrates', 'name')
                ->columns(2)
                ->columnSpanFull()
                ->helperText('Only these substrates can be chosen with this medium.'),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            TextColumn::make('kind')->badge(),
            TextColumn::make('price_factor')->label('Factor')->prefix('× ')->alignEnd(),
            // All substrates shown, wrapping onto new lines (Filament's "Show more" toggle didn't respond).
            TextColumn::make('substrates.name')->label('Substrates')->badge()->wrap(),
        ], [
            SelectFilter::make('kind')->options(MediumKind::class),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMediums::route('/'),
        ];
    }

    private static function isPrint(mixed $kind): bool
    {
        $kind = $kind instanceof MediumKind ? $kind : MediumKind::tryFrom((string) $kind);

        return $kind === MediumKind::Print;
    }
}
