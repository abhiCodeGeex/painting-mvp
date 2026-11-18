<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

/**
 * Surface finish of a substrate.
 */
enum SubstrateFinish: string implements HasLabel
{
    use HasOptions;

    case Matte = 'matte';
    case Glossy = 'glossy';
    case Textured = 'textured';

    public function getLabel(): string
    {
        return match ($this) {
            self::Matte => 'Matte',
            self::Glossy => 'Glossy',
            self::Textured => 'Textured',
        };
    }
}
