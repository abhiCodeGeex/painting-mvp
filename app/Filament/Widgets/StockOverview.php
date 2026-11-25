<?php

namespace App\Filament\Widgets;

use App\Enums\StockStatus;
use App\Filament\Resources\Inventory\StockMovements\StockMovementResource;
use App\Filament\Support\StockColumns;
use App\Models\StockItem;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Top of the dashboard: how many stock items are out / low. Tinted red / amber when non-zero.
 * Each warning links to the item list (frames, mats…) that holds those items, filtered.
 */
class StockOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected ?string $heading = 'Stock';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return StockColumns::canView();
    }

    protected function getStats(): array
    {
        $count = StockColumns::attentionCounts();
        $total = StockItem::query()->current()->count();

        return [
            Stat::make('Out of stock', $count['out'])
                ->icon('heroicon-o-x-circle')
                ->description($count['out'] > 0 ? $this->breakdown(StockStatus::Out) : 'Nothing is out of stock')
                ->descriptionIcon($count['out'] > 0 ? 'heroicon-m-arrow-top-right-on-square' : 'heroicon-m-check')
                ->color($count['out'] > 0 ? 'danger' : 'success')
                ->url($this->listingFor(StockStatus::Out))
                ->extraAttributes(['class' => $count['out'] > 0 ? 'stock-stat-out' : '']),
            Stat::make('Low stock', $count['low'])
                ->icon('heroicon-o-exclamation-triangle')
                ->description($count['low'] > 0 ? $this->breakdown(StockStatus::Low) : 'All above alert levels')
                ->descriptionIcon($count['low'] > 0 ? 'heroicon-m-arrow-top-right-on-square' : 'heroicon-m-check')
                ->color($count['low'] > 0 ? 'warning' : 'success')
                ->url($this->listingFor(StockStatus::Low))
                ->extraAttributes(['class' => $count['low'] > 0 ? 'stock-stat-low' : '']),
            Stat::make('Stocked items', $total)
                ->icon('heroicon-o-archive-box')
                ->description(($total - $count['out'] - $count['low']).' in stock · view history')
                ->color('gray')
                ->url(StockMovementResource::getUrl('index')),
        ];
    }

    /**
     * @return array<class-string, int> counts per item type, in catalogue order
     */
    private function countsByType(StockStatus $status): array
    {
        $counts = StockItem::query()->current()->withStatus($status)
            ->selectRaw('stockable_type, COUNT(*) AS aggregate')
            ->groupBy('stockable_type')
            ->pluck('aggregate', 'stockable_type');

        return collect(StockItem::TYPES)
            ->keys()
            ->mapWithKeys(fn (string $type): array => [$type => (int) ($counts[$type] ?? 0)])
            ->filter()
            ->all();
    }

    /**
     * e.g. "Frame moulding 2 · Mat board 1".
     */
    private function breakdown(StockStatus $status): string
    {
        return collect($this->countsByType($status))
            ->map(fn (int $n, string $type): string => StockItem::TYPES[$type].' '.$n)
            ->implode(' · ');
    }

    /**
     * The list holding the first such items, filtered to that status.
     */
    private function listingFor(StockStatus $status): ?string
    {
        $type = array_key_first($this->countsByType($status));

        return $type ? StockColumns::typeListingUrl($type, $status->value) : null;
    }
}
