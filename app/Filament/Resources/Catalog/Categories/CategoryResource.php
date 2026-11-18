<?php

namespace App\Filament\Resources\Catalog\Categories;

use App\Filament\Resources\Catalog\Categories\Pages\ManageCategories;
use App\Filament\Resources\Catalog\Categories\Pages\ManageCategorySubcategories;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterResource;
use App\Filament\Support\MasterTable;
use App\Models\Category;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Top-level artwork categories (Canvas Wall Art, Hand Painted…). Subcategories have their own list.
 */
class CategoryResource extends MasterResource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_CATALOG;

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $modelLabel = 'category';

    protected static ?string $pluralModelLabel = 'categories';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'catalog/categories';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('parent_id');
    }

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
            TextInput::make('material')
                ->maxLength(255)
                ->placeholder('e.g. Canvas fabric, Manual painting, Various materials')
                ->columnSpanFull(),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable()->weight('bold'),
            TextColumn::make('material')->placeholder('—'),
            TextColumn::make('description')->limit(45)->placeholder('—')->toggleable(),
            // Click-through to this category's own subcategory list.
            TextColumn::make('children_count')
                ->counts('children')
                ->label('Subcategories')
                ->formatStateUsing(fn (int $state) => $state.' '.Str::plural('subcategory', $state))
                ->icon(Heroicon::OutlinedChevronRight)
                ->iconPosition(IconPosition::After)
                ->color('primary')
                ->weight('medium')
                ->url(fn (Category $record) => self::getUrl('subcategories', ['record' => $record])),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make()->label('Archive')->modalHeading('Archive this category?')
                ->modalDescription('It and its subcategories will be hidden from new enquiries but stay on existing records. You can restore it later.'),
            RestoreAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCategories::route('/'),
            'subcategories' => ManageCategorySubcategories::route('/{record}/subcategories'),
        ];
    }
}
