<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum FrameMaterial: string implements HasLabel
{
    use HasOptions;

    case Wood = 'wood';
    case Aluminium = 'aluminium';
    case Polymer = 'polymer';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Wood => 'Wood',
            self::Aluminium => 'Aluminium',
            self::Polymer => 'Polymer / PS',
            self::Other => 'Other',
        };
    }
}
