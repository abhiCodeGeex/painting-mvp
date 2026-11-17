<?php

namespace App\Filament\Resources\Sales\Quotes\Pages;

use App\Filament\Resources\Sales\Quotes\QuoteResource;
use Filament\Resources\Pages\ListRecords;

class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;

    public function getSubheading(): ?string
    {
        return 'To prepare a new quote, open an enquiry and click “Create quote”.';
    }
}
