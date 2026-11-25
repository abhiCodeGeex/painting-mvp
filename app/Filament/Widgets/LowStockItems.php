<?php

namespace App\Filament\Widgets;

use App\Enums\StockStatus;
use App\Filament\Resources\Inventory\StockMovements\StockMovementResource;
use App\Filament\Support\StockColumns;
use App\Models\StockItem;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard list of items that are low or out of stock. Each row opens the item in its own
 * listing (frames, mats, glazing, substrates), filtered to it; Refill works right here too.
 */
class LowStockItems extends TableWidget
{
    protected static ?int $sort = -9;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return StockColumns::canView();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(StockItem::query()->current()->needsAttention()->with('stockable'))
            ->heading('Needs restocking')
            ->description('Out of stock first, then lowest quantity. Click an item to open it in its list.')
            ->columns([
                TextColumn::make('item')
                    ->label('Item')
                    ->state(fn (StockItem $record): string => $record->name())
                    ->description(fn (StockItem $record): ?string => $record->code())
                    ->weight('semibold'),
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
                    ->alignEnd(),
                TextColumn::make('low_stock_threshold')
                    ->label('Alert under')
                    ->formatStateUsing(fn (StockItem $record): string => StockItem::formatQuantity($record->low_stock_threshold, $record->unit))
                    ->alignEnd(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (StockItem $record): StockStatus => $record->status())
                    ->badge(),
            ])
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('CASE WHEN quantity <= 0 THEN 0 WHEN quantity < low_stock_threshold THEN 1 ELSE 2 END')
                ->orderBy('quantity')
                ->orderBy('id'))
            ->recordClasses(fn (StockItem $record): ?string => match ($record->status()) {
                StockStatus::Out => 'stock-row-out',
                StockStatus::Low => 'stock-row-low',
                default => null,
            })
            ->recordUrl(fn (StockItem $record): ?string => StockColumns::listingUrl($record))
            ->recordActions([
                StockColumns::refill(),
                Action::make('openItem')
                    ->label('Open in list')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (StockItem $record): ?string => StockColumns::listingUrl($record)),
            ])
            ->headerActions([
                Action::make('history')
                    ->label('Stock history')
                    ->icon('heroicon-m-clock')
                    ->color('gray')
                    ->url(StockMovementResource::getUrl('index')),
            ])
            ->emptyStateHeading('Nothing needs restocking')
            ->emptyStateDescription('Every stock item is above its alert level.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
