<?php

namespace App\Filament\Resources\Inventory\StockMovements\Pages;

use App\Filament\Resources\Inventory\StockMovements\StockMovementResource;
use Filament\Resources\Pages\ListRecords;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected static ?string $title = 'Stock movements';

    protected ?string $subheading = 'Every stock change: deliveries, usage and stock counts, with who made it.';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
