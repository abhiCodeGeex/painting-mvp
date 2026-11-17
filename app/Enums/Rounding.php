<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum Rounding: string implements HasLabel
{
    use HasOptions;

    case None = 'none';
    case Paisa = '0.01';
    case Rupee = '1';
    case FiveRupees = '5';
    case TenRupees = '10';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'No rounding',
            self::Paisa => 'Nearest ₹0.01',
            self::Rupee => 'Nearest ₹1',
            self::FiveRupees => 'Nearest ₹5',
            self::TenRupees => 'Nearest ₹10',
        };
    }
}
