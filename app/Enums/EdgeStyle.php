<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum EdgeStyle: string implements HasLabel
{
    use HasOptions;

    case WrappedImage = 'wrapped_image';
    case Mirrored = 'mirrored';
    case SolidColour = 'solid_colour';

    public function getLabel(): string
    {
        return match ($this) {
            self::WrappedImage => 'Wrapped image',
            self::Mirrored => 'Mirrored',
            self::SolidColour => 'Solid colour',
        };
    }
}
