<?php

namespace App\Filament\Resources\Catalog\Categories\Pages;

use App\Filament\Resources\Catalog\Categories\CategoryResource;
use App\Filament\Support\MasterForm;
use App\Filament\Support\MasterTable;
use App\Models\Category;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * Subcategories of one category: Categories › Canvas Paintings › Subcategories.
 * New subcategories created here belong to this category automatically.
 */
class ManageCategorySubcategories extends ManageRelatedRecords
{
    protected static string $resource = CategoryResource::class;

    protected static string $relationship = 'children';

    protected static ?string $breadcrumb = 'Subcategories';

    protected static ?string $modelLabel = 'subcategory';

    protected static ?string $pluralModelLabel = 'subcategories';

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    public function getSubheading(): ?string
    {
        $count = $this->getRecord()->children()->count();

        return "{$count} ".Str::plural('subcategory', $count).' · '.($this->getRecord()->material ?: 'No material set');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('allCategories')
                ->label('All categories')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(CategoryResource::getUrl('index')),
        ];
    }

    public function form(Schema $schema): Schema
    {
        $parentSlug = $this->getRecord()->slug;

        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                // Slug is "<category>-<name>", so the same name can exist under different categories.
                ->afterStateUpdated(function (Set $set, ?string $state, string $operation) use ($parentSlug): void {
                    if ($operation === 'create') {
                        $set('slug', Str::slug("{$parentSlug} {$state}"));
                    }
                }),
            TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->alphaDash()
                ->unique(Category::class, 'slug', ignoreRecord: true),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            ...MasterForm::status(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return MasterTable::configure($table, [
            TextColumn::make('name')->searchable()->weight('semibold'),
            TextColumn::make('description')->limit(70)->placeholder('—')->toggleable(),
            TextColumn::make('artworks_count')->counts('artworks')->label('Artworks')->alignEnd(),
        ])
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([SoftDeletingScope::class]))
            ->headerActions([
                CreateAction::make()->label('New subcategory')->icon(Heroicon::OutlinedPlus),
            ])
            ->emptyStateHeading('No subcategories yet')
            ->emptyStateDescription('Add the first subcategory for this category.');
    }
}
