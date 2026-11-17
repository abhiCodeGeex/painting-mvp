<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum ReflectionLevel: string implements HasLabel
{
    use HasOptions;

    case None = 'none';
    case Standard = 'standard';
    case Low = 'low';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Standard => 'Standard',
            self::Low => 'Low / anti-reflective',
        };
    }
}
