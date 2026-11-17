<?php

namespace App\Filament\Resources\Framing\Frames\Pages;

use App\Filament\Resources\Framing\Frames\FrameResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFrames extends ListRecords
{
    protected static string $resource = FrameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
