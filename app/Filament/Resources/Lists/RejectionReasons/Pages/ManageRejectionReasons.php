<?php

namespace App\Filament\Resources\Lists\RejectionReasons\Pages;

use App\Filament\Resources\Lists\RejectionReasons\RejectionReasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRejectionReasons extends ManageRecords
{
    protected static string $resource = RejectionReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
