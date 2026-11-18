<?php

namespace App\Filament\Resources\Catalog\Artworks\Tables;

use App\Enums\ArtworkPricingModel;
use App\Enums\ArtworkType;
use App\Enums\PrintingMethod;
use App\Enums\SubstrateFinish;
use App\Enums\SubstrateMaterial;
use App\Filament\Resources\Catalog\Artworks\Pages\ListArtworks;
use App\Filament\Support\MasterTable;
use App\Models\Artwork;
use App\Models\Category;
use App\Support\Money;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Artworks list: picture cards by default (what the art looks like matters most), or a compact table.
 */
class ArtworksTable
{
    public static function configure(Table $table): Table
    {
        $livewire = $table->getLivewire();
        $grid = ! $livewire instanceof ListArtworks || $livewire->isGrid();

        $table = MasterTable::configure($table, self::listColumns(), self::filters())
            ->modifyQueryUsing(fn ($query) => $query->with(['artist', 'categories.parent', 'sizePrices']));

        if (! $grid) {
            return $table;
        }

        return $table
            ->columns(self::cardColumns())
            ->contentGrid(['sm' => 2, 'lg' => 3, '2xl' => 4])
            ->paginated([12, 24, 48, 'all'])
            ->defaultPaginationPageOption(12)
            ->reorderable(false);
    }

    /**
     * @return array<int, mixed>
     */
    private static function cardColumns(): array
    {
        return [
            Stack::make([
                SpatieMediaLibraryImageColumn::make('main_image')
                    ->collection('main')
                    ->conversion('thumb')
                    ->imageHeight('13rem')
                    ->width('100%')
                    ->extraImgAttributes(['class' => 'w-full rounded-lg object-cover bg-gray-100 dark:bg-white/5', 'style' => 'height: 13rem; width: 100%;', 'loading' => 'lazy'])
                    ->defaultImageUrl(fn () => 'data:image/svg+xml;utf8,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 4 3"><rect width="4" height="3" fill="#9ca3af" opacity=".15"/></svg>')),
                Stack::make([
                    Split::make([
                        TextColumn::make('title')
                            ->searchable()
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large)
                            ->lineClamp(1),
                        // Only featured artworks get the star; nothing is shown otherwise.
                        TextColumn::make('featured_flag')
                            ->state(fn (Artwork $record) => $record->is_featured ? 'Featured' : null)
                            ->badge()
                            ->icon(Heroicon::Star)
                            ->color('warning')
                            ->size(TextSize::ExtraSmall)
                            ->grow(false),
                    ])->extraAttributes(['class' => 'items-center']),
                    TextColumn::make('artist_line')
                        ->state(fn (Artwork $record) => collect([$record->artist?->name ?? 'Studio', $record->code])->implode(' · '))
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->searchable(['code']),
                    // "Canvas Wall Art: Single Design, Set of 2 Designs" — one line per category, wraps instead of being cut off.
                    TextColumn::make('category_list')
                        ->state(fn (Artwork $record) => self::categoryLines($record))
                        ->listWithLineBreaks()
                        ->icon(Heroicon::OutlinedTag)
                        ->color('gray')
                        ->size(TextSize::ExtraSmall)
                        ->wrap(),
                    Split::make([
                        TextColumn::make('type')->badge()->size(TextSize::ExtraSmall)->grow(false),
                        TextColumn::make('card_price')
                            ->state(fn (Artwork $record) => self::priceSummary($record, short: true))
                            ->weight(FontWeight::Bold)
                            ->alignEnd(),
                    ])->extraAttributes(['class' => 'items-center pt-1']),
                    TextColumn::make('is_active')
                        ->state(fn (Artwork $record) => $record->is_active ? null : 'Hidden from customers')
                        ->badge()
                        ->color('danger')
                        ->size(TextSize::ExtraSmall),
                ])->space(2)->extraAttributes(['class' => 'pt-3']),
            ]),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function listColumns(): array
    {
        return [
            SpatieMediaLibraryImageColumn::make('main_image')
                ->label('')
                ->collection('main')
                ->conversion('thumb')
                ->imageSize(64)
                ->extraImgAttributes(['class' => 'rounded-md object-cover']),
            TextColumn::make('title')
                ->searchable()
                ->sortable()
                ->weight(FontWeight::SemiBold)
                ->description(fn (Artwork $record) => collect([$record->artist?->name ?? 'Studio', $record->code])->implode(' · ')),
            TextColumn::make('code')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('categories.name')
                ->label('Categories')
                ->state(fn (Artwork $record) => $record->categories->map(fn (Category $c) => $c->fullName())->all())
                ->badge()
                ->color('gray')
                ->toggleable(),
            TextColumn::make('type')->badge(),
            TextColumn::make('price_summary')
                ->label('Price')
                ->state(fn (Artwork $record) => self::priceSummary($record))
                ->description(fn (Artwork $record) => $record->pricing_model?->getLabel())
                ->alignEnd(),
            IconColumn::make('is_featured')->label('Featured')->boolean()->toggleable(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function categoryLines(Artwork $record): array
    {
        return $record->categories
            ->groupBy(fn (Category $c) => $c->parent?->name ?? $c->name)
            ->map(fn ($group, string $parent) => ($names = $group->filter(fn (Category $c) => $c->parent_id)->pluck('name'))->isEmpty()
                ? $parent
                : $parent.': '.$names->implode(', '))
            ->values()
            ->all();
    }

    /**
     * Readable price: "₹9,500 / m²", "₹45,000" or "From ₹6,500 · 3 sizes" (short: no size count, no ".00").
     */
    private static function priceSummary(Artwork $record, bool $short = false): string
    {
        $fmt = fn (int $paise) => $short ? preg_replace('/\.00$/', '', Money::format($paise)) : Money::format($paise);

        if ($record->pricing_model === ArtworkPricingModel::PerSquareMetre) {
            return $record->price_per_m2 ? $fmt($record->price_per_m2).' / m²' : 'Price on request';
        }

        $prices = $record->sizePrices->pluck('price')->filter();

        return match (true) {
            $prices->isEmpty() => 'Price on request',
            $prices->count() === 1 => $fmt($prices->first()),
            $short => 'From '.$fmt($prices->min()),
            default => 'From '.$fmt($prices->min()).' · '.$prices->count().' sizes',
        };
    }

    /**
     * @return array<int, mixed>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('type')->options(ArtworkType::class),
            SelectFilter::make('artist_id')->label('Artist')->relationship('artist', 'name')->searchable()->preload(),

            // Top-level category: matches artworks in the category itself or any of its subcategories.
            SelectFilter::make('category')
                ->label('Category')
                ->options(fn () => Category::query()->whereNull('parent_id')->orderBy('sort_order')->pluck('name', 'id'))
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->whereHas('categories', fn (Builder $q) => $q
                        ->where('categories.id', $data['value'])
                        ->orWhere('categories.parent_id', $data['value']))
                    : $query),

            SelectFilter::make('subcategory')
                ->label('Subcategory')
                ->multiple()
                ->options(fn () => Category::query()
                    ->whereNotNull('parent_id')
                    ->with('parent')
                    ->orderBy('parent_id')->orderBy('sort_order')
                    ->get()
                    ->groupBy(fn (Category $c) => $c->parent->name)
                    ->map(fn ($group) => $group->pluck('name', 'id')->all())
                    ->all())
                ->query(fn (Builder $query, array $data) => filled($data['values'] ?? null)
                    ? $query->whereHas('categories', fn (Builder $q) => $q->whereIn('categories.id', $data['values']))
                    : $query),

            SelectFilter::make('material_type')
                ->label('Material type')
                ->options(SubstrateMaterial::class)
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->whereHas('substrates', fn (Builder $q) => $q->where('material_type', $data['value']))
                    : $query),

            SelectFilter::make('finish')
                ->options(SubstrateFinish::class)
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->whereHas('substrates', fn (Builder $q) => $q->where('finish', $data['value']))
                    : $query),

            SelectFilter::make('printing_method')
                ->label('Printing method')
                ->options(PrintingMethod::class)
                ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                    ? $query->whereHas('substrates', fn (Builder $q) => $q->whereJsonContains('printing_methods', $data['value']))
                    : $query),

            TernaryFilter::make('is_featured')->label('Featured'),
        ];
    }
}
