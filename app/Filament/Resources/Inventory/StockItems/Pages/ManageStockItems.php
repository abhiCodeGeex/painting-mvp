<?php

namespace App\Filament\Resources\Inventory\StockItems\Pages;

use App\Filament\Resources\Inventory\StockItems\StockActions;
use App\Filament\Resources\Inventory\StockItems\StockItemResource;
use Filament\Resources\Pages\ManageRecords;

class ManageStockItems extends ManageRecords
{
    protected static string $resource = StockItemResource::class;

    protected static ?string $title = 'Stock';

    protected ?string $subheading = 'Frame moulding, mats, glazing and substrates on hand. Amber = low (under the alert level, 20 by default), red = out of stock.';

    protected function getHeaderActions(): array
    {
        return [
            StockActions::exportCsv(),
        ];
    }
}
