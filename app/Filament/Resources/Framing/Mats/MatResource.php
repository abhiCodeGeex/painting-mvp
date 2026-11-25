<?php

namespace App\Filament\Resources\Framing\Mats;

use App\Filament\Resources\Framing\Mats\Pages\ManageMats;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\StockColumns;
use App\Models\Mat;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Mat / passe-partout boards.
 */
class MatResource extends MasterResource
{
    protected static ?string $model = Mat::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquare2Stack;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_FRAMING;

    protected static ?string $navigationLabel = 'Mats';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->maxLength(50)->unique(ignoreRecord: true),
            TextInput::make('name')->required()->maxLength(255)->placeholder('e.g. Ivory 1.4 mm'),
            ColorPicker::make('colour_hex')->label('Face colour')->required()->hex()->live(),
            ColorPicker::make('core_colour_hex')->label('Bevel / core colour')->required()->hex()->default('#FFFFFF')->live(),
            TextInput::make('thickness_mm')->label('Thickness')->numeric()->step(0.1)->minValue(0.5)->suffix('mm')->default(1.4)->required(),
            MasterForm::mm('default_border_mm', 'Default border')->required()->default(50)->live(onBlur: true),
            MasterForm::mm('bottom_weight_mm', 'Extra bottom border')->default(0)->live(onBlur: true)
                ->helperText('Optional "weighted" bottom margin.'),
            Section::make('Preview')
                ->columnSpanFull()
                ->schema([
                    View::make('filament.forms.mat-live-preview')
                        ->viewData(fn (Get $get): array => [
                            'colour' => $get('colour_hex'),
                            'core' => $get('core_colour_hex'),
                            'border' => $get('default_border_mm'),
                            'bottom' => $get('bottom_weight_mm'),
                        ]),
                ]),

            Section::make('Cost & sheet size')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    MoneyInput::make('cost_per_m2')->label('Cost per m²')->required(),
                    MoneyInput::make('cut_fee')->label('Cutting fee per piece')->default(0),
                    MasterForm::mm('max_sheet_width_mm', 'Max sheet width'),
                    MasterForm::mm('max_sheet_height_mm', 'Max sheet height'),
                ]),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return StockColumns::apply(MasterTable::configure($table, [
            ViewColumn::make('preview')->label('Preview')->view('filament.columns.mat-preview'),
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('thickness_mm')->label('Thickness')->suffix(' mm')->alignEnd(),
            MasterTable::mm('default_border_mm', 'Border'),
            MasterTable::money('cost_per_m2', 'Cost / m²'),
            ...StockColumns::columns(),
        ]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMats::route('/'),
        ];
    }
}
