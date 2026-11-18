<?php

namespace App\Filament\Resources\Catalog\StandardSizes;

use App\Filament\Resources\Catalog\StandardSizes\Pages\ManageStandardSizes;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Models\StandardSize;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Preset sizes offered in the enquiry form (orientation-agnostic: 50 × 70 also covers 70 × 50).
 */
class StandardSizeResource extends MasterResource
{
    protected static ?string $model = StandardSize::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsPointingOut;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_MATERIALS;

    protected static ?string $navigationLabel = 'Standard sizes';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            MasterForm::cm('width_mm', 'Width')->required()->minValue(1)->live(onBlur: true),
            MasterForm::cm('height_mm', 'Height')->required()->minValue(1)->live(onBlur: true),
            TextInput::make('label')
                ->maxLength(50)
                ->columnSpanFull()
                ->placeholder(fn (Get $get): string => ((float) $get('width_mm') && (float) $get('height_mm'))
                    ? StandardSize::defaultLabel((int) round((float) $get('width_mm') * 10), (int) round((float) $get('height_mm') * 10))
                    : 'e.g. A2 or 50 × 70 cm')
                ->helperText('Leave blank to use the size in cm.'),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            ViewColumn::make('preview')->label('Shape')->view('filament.columns.size-preview'),
            TextColumn::make('label')->searchable(),
            MasterTable::cm('width_mm', 'Width'),
            MasterTable::cm('height_mm', 'Height'),
            TextColumn::make('inches')
                ->label('Inches')
                ->state(fn (StandardSize $record) => round($record->width_mm / 25.4, 1).' × '.round($record->height_mm / 25.4, 1).' in')
                ->color('gray')
                ->alignEnd()
                ->toggleable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStandardSizes::route('/'),
        ];
    }
}
