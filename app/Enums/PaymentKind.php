<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

/** The two parts of an order's payment: a token on placing it, the balance on delivery. */
enum PaymentKind: string implements HasLabel
{
    use HasOptions;

    case Token = 'token';
    case Final = 'final';

    public function getLabel(): string
    {
        return match ($this) {
            self::Token => 'Token amount',
            self::Final => 'Full & final',
        };
    }
}
