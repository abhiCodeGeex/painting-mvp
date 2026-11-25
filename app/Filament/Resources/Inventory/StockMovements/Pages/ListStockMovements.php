<?php

namespace App\Filament\Resources\Inventory\StockMovements\Pages;

use App\Filament\Resources\Inventory\StockMovements\StockMovementResource;
use Filament\Resources\Pages\ListRecords;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected static ?string $title = 'Stock history';

    protected ?string $subheading = 'Every stock change (refills, reductions, stock counts) with who made it. Stock is refilled or reduced from each item\'s own list.';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
