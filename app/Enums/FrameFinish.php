<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum FrameFinish: string implements HasLabel
{
    use HasOptions;

    case Matte = 'matte';
    case Satin = 'satin';
    case Gloss = 'gloss';
    case Metallic = 'metallic';

    public function getLabel(): string
    {
        return match ($this) {
            self::Matte => 'Matte',
            self::Satin => 'Satin',
            self::Gloss => 'Gloss',
            self::Metallic => 'Metallic',
        };
    }
}
