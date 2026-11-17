<?php

namespace App\Filament\Resources\Framing\Frames\Schemas;

use App\Enums\FrameFinish;
use App\Enums\FrameMaterial;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MoneyInput;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class FrameForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        Section::make('Moulding')
                            ->columns(3)
                            ->schema([
                                TextInput::make('code')
                                    ->required()
                                    ->maxLength(50)
                                    ->unique(ignoreRecord: true)
                                    ->placeholder('e.g. FR-WAL-25'),
                                TextInput::make('name')->required()->maxLength(255)->columnSpan(2)
                                    ->placeholder('e.g. Walnut flat 25 mm'),
                                TextInput::make('supplier_code')->maxLength(100),
                                Select::make('material')->options(FrameMaterial::class)->required()->native(false),
                                Select::make('finish')->options(FrameFinish::class)->required()->native(false)->live(),
                                ColorPicker::make('colour_hex')->label('Colour')->required()->hex()->live(),
                                Toggle::make('is_floater')
                                    ->label('Floater frame (canvas floats inside)')
                                    ->inline(false)
                                    ->columnSpan(2),
                            ]),

                        Section::make('Profile')
                            ->description('Used for price (frame length) and for the 3D preview.')
                            ->columns(3)
                            ->schema([
                                MasterForm::mm('profile_width_mm', 'Face width')->required()->minValue(5)->live(onBlur: true),
                                MasterForm::mm('profile_depth_mm', 'Depth')->required()->minValue(5),
                                MasterForm::mm('rebate_depth_mm', 'Rebate depth'),
                            ]),

                        Section::make('Size limits (inside the frame)')
                            ->columns(2)
                            ->collapsible()
                            ->schema([
                                MasterForm::mm('min_inner_width_mm', 'Min width'),
                                MasterForm::mm('min_inner_height_mm', 'Min height'),
                                MasterForm::mm('max_inner_width_mm', 'Max width'),
                                MasterForm::mm('max_inner_height_mm', 'Max height'),
                                MasterForm::mm('max_perimeter_mm', 'Max perimeter')
                                    ->helperText('Leave empty if there is no limit.'),
                            ]),

                        Section::make('Suitable substrates')
                            ->collapsible()
                            ->schema([
                                CheckboxList::make('substrates')
                                    ->hiddenLabel()
                                    ->relationship('substrates', 'name', fn (Builder $query) => $query->active()->ordered())
                                    ->columns(2)
                                    ->helperText('Leave empty to allow all substrates.'),
                            ]),

                        Textarea::make('description')->rows(3),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Preview')
                            ->description('Updates as you change colour, width and finish.')
                            ->schema([
                                View::make('filament.forms.frame-live-preview')
                                    ->viewData(fn (Get $get): array => [
                                        'colour' => $get('colour_hex'),
                                        'width' => $get('profile_width_mm'),
                                        'finish' => $get('finish'),
                                    ]),
                            ]),
                        Section::make('Cost')
                            ->schema([
                                MoneyInput::make('price_per_m')->label('Cost per metre')->required(),
                                MasterForm::percent('waste_percent', 'Waste allowance')->default(10)
                                    ->helperText('Extra length for mitre cuts and offcuts.'),
                            ]),
                        Section::make('Images')
                            ->schema([
                                SpatieMediaLibraryFileUpload::make('corner')
                                    ->label('Corner sample')
                                    ->collection('corner')
                                    ->image()
                                    ->maxSize(5120)
                                    ->helperText('Shown in the customer\'s frame picker.'),
                                SpatieMediaLibraryFileUpload::make('profile')
                                    ->label('Profile / cross-section')
                                    ->collection('profile')
                                    ->image()
                                    ->maxSize(5120),
                                SpatieMediaLibraryFileUpload::make('texture')
                                    ->label('Texture for 3D (optional)')
                                    ->collection('texture')
                                    ->image()
                                    ->maxSize(5120)
                                    ->helperText('Seamless wood-grain or finish texture.'),
                            ]),
                        Section::make('Status')
                            ->schema(MasterForm::status()),
                    ]),
            ]);
    }
}
