<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum GlazingMaterial: string implements HasLabel
{
    use HasOptions;

    case None = 'none';
    case Glass = 'glass';
    case Acrylic = 'acrylic';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Glass => 'Glass',
            self::Acrylic => 'Acrylic',
        };
    }
}
