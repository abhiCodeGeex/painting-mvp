<?php

namespace App\Filament\Resources\Sales\Orders\Pages;

use App\Filament\Resources\Sales\Orders\OrderResource;
use App\Filament\Resources\Sales\Orders\Widgets\OrderMoneyOverview;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** Orders value, expenses, money received and profit, above the list. */
    protected function getHeaderWidgets(): array
    {
        return [OrderMoneyOverview::class];
    }
}
