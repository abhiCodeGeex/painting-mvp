<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

/**
 * Unit stock is counted in: metres of moulding, sheets of board / glass, rolls, or m².
 */
enum StockUnit: string implements HasLabel
{
    use HasOptions;

    case Metre = 'm';
    case Sheet = 'sheet';
    case Roll = 'roll';
    case SquareMetre = 'm2';

    public function getLabel(): string
    {
        return match ($this) {
            self::Metre => 'm',
            self::Sheet => 'sheets',
            self::Roll => 'rolls',
            self::SquareMetre => 'm²',
        };
    }

    /**
     * Whole pieces (sheets, rolls) are counted in integers; lengths and areas take decimals.
     */
    public function allowsFractions(): bool
    {
        return $this === self::Metre || $this === self::SquareMetre;
    }
}
