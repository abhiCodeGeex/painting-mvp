<?php

namespace App\Filament\Resources\Framing\Frames\Pages;

use App\Filament\Resources\Framing\Frames\FrameResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFrame extends CreateRecord
{
    protected static string $resource = FrameResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
