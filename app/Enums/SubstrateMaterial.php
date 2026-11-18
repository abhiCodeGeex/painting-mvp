<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

/**
 * Material a substrate is made of.
 */
enum SubstrateMaterial: string implements HasLabel
{
    use HasOptions;

    case Canvas = 'canvas';
    case Paper = 'paper';
    case Wood = 'wood';
    case Glass = 'glass';

    public function getLabel(): string
    {
        return match ($this) {
            self::Canvas => 'Canvas',
            self::Paper => 'Paper',
            self::Wood => 'Wood',
            self::Glass => 'Glass',
        };
    }
}
