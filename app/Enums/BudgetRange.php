<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum BudgetRange: string implements HasLabel
{
    use HasOptions;

    case Under25k = 'under_25k';
    case From25kTo1L = '25k_1l';
    case From1LTo5L = '1l_5l';
    case Above5L = 'above_5l';
    case NotSure = 'not_sure';

    public function getLabel(): string
    {
        return match ($this) {
            self::Under25k => 'Under ₹25,000',
            self::From25kTo1L => '₹25,000 – ₹1 lakh',
            self::From1LTo5L => '₹1 – ₹5 lakh',
            self::Above5L => 'Above ₹5 lakh',
            self::NotSure => 'Not sure yet',
        };
    }
}
