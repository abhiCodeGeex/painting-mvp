<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum ExtraChargeType: string implements HasLabel
{
    use HasOptions;

    case Packing = 'packing';
    case Shipping = 'shipping';
    case Installation = 'installation';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Packing => 'Packing',
            self::Shipping => 'Shipping',
            self::Installation => 'Installation',
            self::Other => 'Other',
        };
    }
}
