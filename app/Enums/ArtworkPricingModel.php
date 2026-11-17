<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum ArtworkPricingModel: string implements HasLabel
{
    use HasOptions;

    case FixedPerSize = 'fixed_per_size';
    case PerSquareMetre = 'per_m2';

    public function getLabel(): string
    {
        return match ($this) {
            self::FixedPerSize => 'Fixed price per size',
            self::PerSquareMetre => 'Price per m²',
        };
    }
}
