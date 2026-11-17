<?php

namespace App\Filament\Resources\Catalog\Artworks\Schemas;

use App\Enums\ArtworkPricingModel;
use App\Enums\ArtworkType;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MoneyInput;
use App\Models\StandardSize;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ArtworkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Artwork')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    self::detailsTab(),
                    self::imagesTab(),
                    self::sizesTab(),
                    self::optionsTab(),
                ]),
        ]);
    }

    private static function detailsTab(): Tab
    {
        return Tab::make('Details')
            ->icon('heroicon-o-information-circle')
            ->columns(2)
            ->schema([
                TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true)
                    ->placeholder('e.g. ART-0001'),
                TextInput::make('title')->required()->maxLength(255),
                Select::make('artist_id')
                    ->label('Artist')
                    ->relationship('artist', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('name')->required()->maxLength(255),
                    ]),
                Select::make('categories')
                    ->label('Categories & styles')
                    ->relationship('categories', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable(),
                Select::make('type')
                    ->options(ArtworkType::class)
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('edition_size')
                    ->label('Edition size')
                    ->integer()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => self::is($get('type'), ArtworkType::PrintLimitedEdition))
                    ->visible(fn (Get $get): bool => self::is($get('type'), ArtworkType::PrintLimitedEdition)),
                TagsInput::make('style_tags')
                    ->label('Tags')
                    ->placeholder('e.g. blue, minimal, large')
                    ->columnSpanFull(),
                Textarea::make('description')->rows(4)->columnSpanFull(),
                TextInput::make('lead_time_days')
                    ->label('Lead time')
                    ->integer()
                    ->minValue(0)
                    ->suffix('days'),
                Toggle::make('is_featured')->label('Featured in catalog')->inline(false),
                ...MasterForm::status(),
            ]);
    }

    private static function imagesTab(): Tab
    {
        return Tab::make('Images')
            ->icon('heroicon-o-photo')
            ->schema([
                SpatieMediaLibraryFileUpload::make('main_image')
                    ->label('Main image')
                    ->collection('main')
                    ->image()
                    ->maxSize(20480)
                    ->helperText('Used in the catalog and as the 3D preview texture. JPG/PNG, up to 20 MB.'),
                SpatieMediaLibraryFileUpload::make('gallery')
                    ->label('Gallery')
                    ->collection('gallery')
                    ->image()
                    ->multiple()
                    ->reorderable()
                    ->maxFiles(12)
                    ->maxSize(20480),
            ]);
    }

    private static function sizesTab(): Tab
    {
        return Tab::make('Sizes & pricing')
            ->icon('heroicon-o-currency-rupee')
            ->schema([
                Section::make('Size limits')
                    ->columns(4)
                    ->schema([
                        MasterForm::mm('native_width_mm', 'Original width'),
                        MasterForm::mm('native_height_mm', 'Original height'),
                        Toggle::make('allow_custom_size')
                            ->label('Customers may enter a custom size')
                            ->default(true)
                            ->inline(false)
                            ->columnSpan(2),
                        MasterForm::mm('min_width_mm', 'Min width'),
                        MasterForm::mm('min_height_mm', 'Min height'),
                        MasterForm::mm('max_width_mm', 'Max width'),
                        MasterForm::mm('max_height_mm', 'Max height'),
                    ]),

                Section::make('Price')
                    ->schema([
                        Select::make('pricing_model')
                            ->label('Pricing model')
                            ->options(ArtworkPricingModel::class)
                            ->default(ArtworkPricingModel::FixedPerSize->value)
                            ->required()
                            ->native(false)
                            ->live(),
                        MoneyInput::make('price_per_m2')
                            ->label('Selling price per m²')
                            ->required(fn (Get $get): bool => self::is($get('pricing_model'), ArtworkPricingModel::PerSquareMetre))
                            ->visible(fn (Get $get): bool => self::is($get('pricing_model'), ArtworkPricingModel::PerSquareMetre))
                            ->helperText('Multiplied by area, medium factor and quality tier factor.'),
                        Repeater::make('sizePrices')
                            ->label('Price per size')
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->visible(fn (Get $get): bool => self::is($get('pricing_model'), ArtworkPricingModel::FixedPerSize))
                            ->columns(4)
                            ->schema([
                                Select::make('standard_size_id')
                                    ->label('Standard size')
                                    ->relationship('standardSize', 'label', fn (Builder $query) => $query->active()->ordered())
                                    ->placeholder('Custom')
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        $size = $state ? StandardSize::find($state) : null;
                                        if ($size) {
                                            $set('width_mm', $size->width_mm);
                                            $set('height_mm', $size->height_mm);
                                        }
                                    }),
                                MasterForm::mm('width_mm', 'Width')->required()->minValue(10),
                                MasterForm::mm('height_mm', 'Height')->required()->minValue(10),
                                MoneyInput::make('price')->label('Selling price')->required(),
                            ])
                            ->addActionLabel('Add size')
                            ->itemLabel(fn (array $state): ?string => ($state['width_mm'] ?? null) && ($state['height_mm'] ?? null)
                                ? StandardSize::defaultLabel((int) $state['width_mm'], (int) $state['height_mm'])
                                : null)
                            ->defaultItems(0),
                    ]),
            ]);
    }

    private static function optionsTab(): Tab
    {
        return Tab::make('Allowed options')
            ->icon('heroicon-o-adjustments-horizontal')
            ->columns(2)
            ->schema([
                Select::make('default_medium_id')
                    ->label('Default medium')
                    ->relationship('defaultMedium', 'name', fn (Builder $query) => $query->active()->ordered())
                    ->preload()
                    ->columnSpanFull(),
                CheckboxList::make('mediums')
                    ->label('Allowed mediums')
                    ->relationship('mediums', 'name', fn (Builder $query) => $query->active()->ordered())
                    ->helperText('Leave empty to allow every active medium.'),
                CheckboxList::make('substrates')
                    ->label('Allowed substrates')
                    ->relationship('substrates', 'name', fn (Builder $query) => $query->active()->ordered())
                    ->helperText('Leave empty to allow every substrate the medium allows.'),
            ]);
    }

    private static function is(mixed $state, ArtworkType|ArtworkPricingModel $expected): bool
    {
        if ($state instanceof $expected) {
            return $state === $expected;
        }

        return $expected::tryFrom((string) $state) === $expected;
    }
}
