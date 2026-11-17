<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Forms\Components\TextInput;

/**
 * Rupee input: the user types rupees (e.g. 1250.50), the database stores paise (125050).
 */
class MoneyInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->prefix('₹')
            ->minValue(0)
            ->step(0.01)
            ->formatStateUsing(fn ($state): ?string => $state === null || $state === '' ? null : Money::toRupees((int) $state))
            ->dehydrateStateUsing(fn ($state): ?int => $state === null || $state === '' ? null : Money::toPaise((string) $state));
    }
}
