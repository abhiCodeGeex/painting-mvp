<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

/**
 * How images are printed onto a substrate.
 */
enum PrintingMethod: string implements HasLabel
{
    use HasOptions;

    case Uv = 'uv';
    case Inkjet = 'inkjet';
    case Sublimation = 'sublimation';

    public function getLabel(): string
    {
        return match ($this) {
            self::Uv => 'UV',
            self::Inkjet => 'Inkjet',
            self::Sublimation => 'Sublimation',
        };
    }
}
