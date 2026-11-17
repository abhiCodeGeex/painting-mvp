<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasLabel
{
    use HasOptions;

    case Individual = 'individual';
    case Company = 'company';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Company => 'Company',
        };
    }
}
