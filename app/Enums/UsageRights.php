<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum UsageRights: string implements HasLabel
{
    use HasOptions;

    case PrivateDisplay = 'private_display';
    case CommercialDisplay = 'commercial_display';
    case Reproduction = 'reproduction';

    public function getLabel(): string
    {
        return match ($this) {
            self::PrivateDisplay => 'Private display',
            self::CommercialDisplay => 'Commercial display (hotel, office…)',
            self::Reproduction => 'Reproduction rights needed',
        };
    }
}
