<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Inventory\StockItems\StockItemResource;
use App\Filament\Resources\Inventory\StockItems\StockItemsTable;
use App\Models\StockItem;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Dashboard list of items that are low or out of stock, with "Adjust stock" on each row.
 */
class LowStockItems extends TableWidget
{
    protected static ?int $sort = -9;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return StockItemResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return StockItemsTable::configure(
            $table->query(StockItem::query()->needsAttention()),
            withFilters: false,
        )
            ->heading('Needs restocking')
            ->description('Out of stock first, then lowest quantity.')
            ->headerActions([
                Action::make('inventory')
                    ->label('Open inventory')
                    ->icon('heroicon-m-archive-box')
                    ->color('gray')
                    ->url(StockItemResource::getUrl('index')),
            ])
            ->emptyStateHeading('Nothing needs restocking')
            ->emptyStateDescription('Every stock item is above its alert level.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5);
    }
}
