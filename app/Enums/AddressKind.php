<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum AddressKind: string implements HasLabel
{
    use HasOptions;

    case Billing = 'billing';
    case Delivery = 'delivery';

    public function getLabel(): string
    {
        return match ($this) {
            self::Billing => 'Billing',
            self::Delivery => 'Delivery',
        };
    }
}
