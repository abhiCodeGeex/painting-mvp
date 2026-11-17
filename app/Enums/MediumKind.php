<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum MediumKind: string implements HasLabel
{
    use HasOptions;

    case HandPainted = 'hand_painted';
    case Print = 'print';

    public function getLabel(): string
    {
        return match ($this) {
            self::HandPainted => 'Hand-painted',
            self::Print => 'Print',
        };
    }
}
