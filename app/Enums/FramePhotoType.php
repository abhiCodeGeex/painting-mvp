<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

/**
 * What an admin's frame photo shows — decides where the moulding strip is cut from.
 */
enum FramePhotoType: string implements HasLabel
{
    use HasOptions;

    case Auto = 'auto';
    case Corner = 'corner';
    case Whole = 'whole';
    case Straight = 'straight';

    public function getLabel(): string
    {
        return match ($this) {
            self::Auto => 'Detect automatically',
            self::Corner => 'Corner close-up',
            self::Whole => 'Whole frame',
            self::Straight => 'Straight piece of moulding',
        };
    }
}
