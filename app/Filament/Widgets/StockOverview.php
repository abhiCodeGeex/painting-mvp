<?php

namespace App\Filament\Widgets;

use App\Enums\StockStatus;
use App\Filament\Resources\Inventory\StockItems\StockItemResource;
use App\Models\StockItem;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Top of the dashboard: how many stock items are out / low. Tinted red / amber when non-zero.
 */
class StockOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected ?string $heading = 'Stock';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return StockItemResource::canViewAny();
    }

    protected function getStats(): array
    {
        $count = StockItemResource::attentionCounts();
        $total = StockItem::query()->current()->count();
        $url = fn (StockStatus $status): string => StockItemResource::getUrl('index', ['filters' => ['status' => ['value' => $status->value]]]);

        return [
            Stat::make('Out of stock', $count['out'])
                ->icon('heroicon-o-x-circle')
                ->description($count['out'] > 0 ? 'Restock before quoting these' : 'Nothing is out of stock')
                ->descriptionIcon($count['out'] > 0 ? 'heroicon-m-arrow-top-right-on-square' : 'heroicon-m-check')
                ->color($count['out'] > 0 ? 'danger' : 'success')
                ->url($url(StockStatus::Out))
                ->extraAttributes(['class' => $count['out'] > 0 ? 'stock-stat-out' : '']),
            Stat::make('Low stock', $count['low'])
                ->icon('heroicon-o-exclamation-triangle')
                ->description($count['low'] > 0 ? 'Under the alert level (20 by default)' : 'All above alert levels')
                ->descriptionIcon($count['low'] > 0 ? 'heroicon-m-arrow-top-right-on-square' : 'heroicon-m-check')
                ->color($count['low'] > 0 ? 'warning' : 'success')
                ->url($url(StockStatus::Low))
                ->extraAttributes(['class' => $count['low'] > 0 ? 'stock-stat-low' : '']),
            Stat::make('Stocked items', $total)
                ->icon('heroicon-o-archive-box')
                ->description(($total - $count['out'] - $count['low']).' in stock')
                ->color('gray')
                ->url(StockItemResource::getUrl('index')),
        ];
    }
}
