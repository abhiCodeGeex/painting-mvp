<?php

namespace App\Filament\Resources\Inventory\StockMovements;

use App\Filament\Resources\Inventory\StockMovements\Pages\ListStockMovements;
use App\Filament\Support\StockColumns;
use App\Models\Frame;
use App\Models\Mat;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Stock history: read-only log of every stock change (refilled, reduced, counted) across all
 * items. Stock itself is managed in each item's own listing (frames, mats, glazing, substrates).
 */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_INVENTORY;

    protected static ?string $navigationLabel = 'Stock history';

    protected static ?string $modelLabel = 'stock movement';

    protected static ?string $slug = 'inventory/movements';

    protected static ?int $navigationSort = 10;

    /**
     * Anyone who may see the stock may see how it changed.
     */
    public static function canViewAny(): bool
    {
        return StockColumns::canView();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['stockItem.stockable', 'user']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('item')
                    ->label('Item')
                    ->state(fn (StockMovement $record): string => $record->stockItem?->name() ?? 'Deleted item')
                    ->description(fn (StockMovement $record): ?string => $record->stockItem?->typeLabel())
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchItem($query, $search)),
                TextColumn::make('change')
                    ->label('Change')
                    ->formatStateUsing(fn (StockMovement $record): string => ($record->change >= 0 ? '+' : '−')
                        .StockItem::formatQuantity(abs($record->change), $record->stockItem?->unit))
                    ->color(fn (StockMovement $record): string => $record->change > 0 ? 'success' : ($record->change < 0 ? 'danger' : 'gray'))
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('quantity_after')
                    ->label('Stock after')
                    ->formatStateUsing(fn (StockMovement $record): string => StockItem::formatQuantity($record->quantity_after, $record->stockItem?->unit))
                    ->alignEnd(),
                TextColumn::make('note')->placeholder('—')->wrap()->searchable(),
                TextColumn::make('user.name')->label('By')->placeholder('System'),
            ])
            ->recordActions([
                Action::make('openItem')
                    ->label('Open item')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (StockMovement $record): bool => $record->stockItem?->stockable !== null)
                    ->url(fn (StockMovement $record): ?string => $record->stockItem ? StockColumns::listingUrl($record->stockItem, null) : null),
            ])
            ->filters([
                SelectFilter::make('direction')
                    ->label('Direction')
                    ->options(['in' => 'Stock in (+)', 'out' => 'Stock out (−)'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'in' => $query->where('change', '>', 0),
                        'out' => $query->where('change', '<', 0),
                        default => $query,
                    }),
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(StockItem::TYPES)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('stockItem', fn (Builder $q) => $q->where('stockable_type', $data['value']))
                        : $query),
                SelectFilter::make('user_id')
                    ->label('By')
                    ->options(fn () => User::staff()->orderBy('name')->pluck('name', 'id')),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->native(false),
                        DatePicker::make('until')->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->emptyStateHeading('No stock movements yet')
            ->emptyStateDescription('Every Refill / Reduce from the frame, mat, glazing and substrate lists is logged here.');
    }

    private static function searchItem(Builder $query, string $search): Builder
    {
        $term = '%'.mb_strtolower($search).'%';

        return $query->whereHas('stockItem', fn (Builder $stock) => $stock->whereHasMorph(
            'stockable',
            array_keys(StockItem::TYPES),
            function (Builder $item, string $type) use ($term): void {
                $item->where(function (Builder $q) use ($type, $term): void {
                    $q->whereRaw('LOWER(name) LIKE ?', [$term]);
                    if (in_array($type, [Frame::class, Mat::class], true)) {
                        $q->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                    }
                });
            },
        ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
