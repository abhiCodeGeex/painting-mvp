<?php

namespace App\Filament\Resources\Inventory\StockItems;

use App\Filament\Resources\Inventory\StockItems\Pages\ManageStockItems;
use App\Models\StockItem;
use App\Providers\Filament\AdminPanelProvider;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Stock on hand for every physical catalogue item (frame mouldings, mats, glazing, substrates).
 * Rows are created with their catalogue item; quantities change only through "Adjust stock".
 */
class StockItemResource extends Resource
{
    protected static ?string $model = StockItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = AdminPanelProvider::GROUP_INVENTORY;

    protected static ?string $navigationLabel = 'Stock';

    protected static ?string $modelLabel = 'stock item';

    protected static ?string $slug = 'inventory/stock';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->current();
    }

    /**
     * Number of items low or out of stock, shown next to "Stock" in the sidebar.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::attentionCounts();

        return $count['low'] + $count['out'] > 0 ? (string) ($count['low'] + $count['out']) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::attentionCounts()['out'] > 0 ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        $count = static::attentionCounts();

        return "{$count['out']} out of stock, {$count['low']} low";
    }

    /**
     * @return array{low: int, out: int}
     */
    public static function attentionCounts(): array
    {
        $row = StockItem::query()->current()
            ->selectRaw('SUM(CASE WHEN quantity <= 0 THEN 1 ELSE 0 END) AS out_count')
            ->selectRaw('SUM(CASE WHEN quantity > 0 AND quantity < low_stock_threshold THEN 1 ELSE 0 END) AS low_count')
            ->toBase()
            ->first();

        return ['low' => (int) ($row->low_count ?? 0), 'out' => (int) ($row->out_count ?? 0)];
    }

    public static function table(Table $table): Table
    {
        return StockItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStockItems::route('/'),
        ];
    }
}
