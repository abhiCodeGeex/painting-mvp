<?php

namespace App\Filament\Resources\Framing\Frames\Pages;

use App\Filament\Resources\Framing\Frames\FrameResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditFrame extends EditRecord
{
    protected static string $resource = FrameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Archive'),
            RestoreAction::make(),
        ];
    }
}
