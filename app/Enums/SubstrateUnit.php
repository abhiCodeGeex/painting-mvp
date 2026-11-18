<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

/**
 * How a substrate is bought / stocked.
 */
enum SubstrateUnit: string implements HasLabel
{
    use HasOptions;

    case Sheet = 'sheet';
    case Roll = 'roll';
    case SquareMetre = 'm2';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sheet => 'Sheet',
            self::Roll => 'Roll',
            self::SquareMetre => 'm²',
        };
    }
}
