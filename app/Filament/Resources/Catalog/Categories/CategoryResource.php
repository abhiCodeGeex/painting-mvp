<?php

namespace App\Filament\Resources\Catalog\Categories;

use App\Filament\Resources\Catalog\Categories\Pages\ManageCategories;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Models\Category;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Artwork categories / styles (abstract, landscape, botanical…), one level of nesting.
 */
class CategoryResource extends MasterResource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_CATALOG;

    protected static ?string $navigationLabel = 'Categories & styles';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
                    if ($operation === 'create') {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->alphaDash()
                ->unique(ignoreRecord: true),
            Select::make('parent_id')
                ->label('Parent category')
                ->relationship(
                    name: 'parent',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query, ?Model $record) => $query
                        ->whereNull('parent_id')
                        ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey())),
                )
                ->searchable()
                ->preload()
                ->placeholder('None (top level)')
                ->columnSpanFull(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable(),
            TextColumn::make('parent.name')->label('Parent')->placeholder('—'),
            TextColumn::make('artworks_count')->counts('artworks')->label('Artworks')->alignEnd(),
        ], [
            SelectFilter::make('parent_id')->label('Parent')->relationship('parent', 'name'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
        ];
    }
}
