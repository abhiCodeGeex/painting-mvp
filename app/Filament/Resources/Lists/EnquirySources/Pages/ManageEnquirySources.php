<?php

namespace App\Filament\Resources\Lists\EnquirySources\Pages;

use App\Filament\Resources\Lists\EnquirySources\EnquirySourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEnquirySources extends ManageRecords
{
    protected static string $resource = EnquirySourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
