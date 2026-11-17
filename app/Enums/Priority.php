<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum Priority: string implements HasLabel
{
    use HasOptions;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function getLabel(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
        };
    }
}
