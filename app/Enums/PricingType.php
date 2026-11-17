<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum PricingType: string implements HasLabel
{
    use HasOptions;

    case Fixed = 'fixed';
    case PerSquareMetre = 'per_m2';
    case PerPerimeterMetre = 'per_perimeter_m';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed per piece',
            self::PerSquareMetre => 'Per m²',
            self::PerPerimeterMetre => 'Per perimeter metre',
        };
    }
}
