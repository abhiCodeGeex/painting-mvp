<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

/**
 * How an extra charge (packing, shipping, installation…) is calculated on a quote.
 */
enum ExtraChargeBasis: string implements HasLabel
{
    use HasOptions;

    case PerQuote = 'per_quote';
    case PerPiece = 'per_piece';
    case PerSquareMetre = 'per_m2';

    public function getLabel(): string
    {
        return match ($this) {
            self::PerQuote => 'Fixed per quote',
            self::PerPiece => 'Per piece',
            self::PerSquareMetre => 'Per m² of artwork',
        };
    }
}
