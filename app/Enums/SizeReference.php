<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum SizeReference: string implements HasLabel
{
    use HasOptions;

    case Image = 'image';
    case Outer = 'outer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Image => 'Artwork / image size',
            self::Outer => 'Outer size including frame',
        };
    }
}
