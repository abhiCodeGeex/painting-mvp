<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum LengthUnit: string implements HasLabel
{
    use HasOptions;

    case Cm = 'cm';
    case Inch = 'inch';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cm => 'cm',
            self::Inch => 'inch',
        };
    }
}
