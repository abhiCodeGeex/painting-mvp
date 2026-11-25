<?php

namespace App\Filament\Resources\Inventory\StockItems;

use App\Enums\StockStatus;
use App\Models\Frame;
use App\Models\Mat;
use App\Models\StockItem;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stock table shared by the Inventory list and the dashboard's "needs attention" widget.
 * Low rows are tinted amber, out-of-stock rows red (styles in AdminPanelProvider).
 */
class StockItemsTable
{
    public static function configure(Table $table, bool $withFilters = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->current()->with('stockable'))
            ->columns(self::columns())
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('CASE WHEN quantity <= 0 THEN 0 WHEN quantity < low_stock_threshold THEN 1 ELSE 2 END')
                ->orderBy('quantity')
                ->orderBy('id'))
            ->filters($withFilters ? self::filters() : [])
            ->recordClasses(fn (StockItem $record): ?string => match ($record->status()) {
                StockStatus::Out => 'stock-row-out',
                StockStatus::Low => 'stock-row-low',
                default => null,
            })
            ->recordActions([
                StockActions::adjust(),
                StockActions::threshold(),
                StockActions::history(),
            ])
            ->recordAction('adjustStock')
            ->toolbarActions($withFilters ? [StockActions::bulkThreshold()] : [])
            ->emptyStateHeading('No stock items')
            ->emptyStateDescription('Frames, mats, glazing and substrates appear here as soon as they are added to the catalogue.');
    }

    /**
     * @return array<int, mixed>
     */
    public static function columns(): array
    {
        return [
            TextColumn::make('item')
                ->label('Item')
                ->state(fn (StockItem $record): string => $record->name())
                ->description(fn (StockItem $record): ?string => $record->code())
                ->weight('semibold')
                ->searchable(query: fn (Builder $query, string $search): Builder => self::search($query, $search)),
            TextColumn::make('stockable_type')
                ->label('Type')
                ->formatStateUsing(fn (string $state): string => StockItem::TYPES[$state] ?? class_basename($state))
                ->badge()
                ->color('gray'),
            TextColumn::make('quantity')
                ->label('In stock')
                ->formatStateUsing(fn (StockItem $record): string => $record->formattedQuantity())
                ->color(fn (StockItem $record): string => $record->status()->getColor())
                ->weight('bold')
                ->alignEnd()
                ->sortable(),
            TextColumn::make('low_stock_threshold')
                ->label('Alert under')
                ->formatStateUsing(fn (StockItem $record): string => StockItem::formatQuantity($record->low_stock_threshold, $record->unit))
                ->alignEnd()
                ->sortable()
                ->toggleable(),
            TextColumn::make('status')
                ->label('Status')
                ->state(fn (StockItem $record): StockStatus => $record->status())
                ->badge(),
            TextColumn::make('updated_at')
                ->label('Last change')
                ->since()
                ->sortable()
                ->toggleable(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function filters(): array
    {
        return [
            SelectFilter::make('status')
                ->label('Stock status')
                ->options(StockStatus::class)
                ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                    ? $query->withStatus(StockStatus::from($data['value']))
                    : $query),
            SelectFilter::make('stockable_type')
                ->label('Type')
                ->options(StockItem::TYPES),
        ];
    }

    private static function search(Builder $query, string $search): Builder
    {
        $term = '%'.mb_strtolower($search).'%';

        return $query->whereHasMorph('stockable', array_keys(StockItem::TYPES), function (Builder $item, string $type) use ($term): void {
            $item->where(function (Builder $q) use ($type, $term): void {
                $q->whereRaw('LOWER(name) LIKE ?', [$term]);

                if (in_array($type, [Frame::class, Mat::class], true)) {
                    $q->orWhereRaw('LOWER(code) LIKE ?', [$term]);
                }
            });
        });
    }
}
