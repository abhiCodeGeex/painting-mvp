<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

/**
 * Shared form fields for masters.
 */
class MasterForm
{
    /**
     * Active flag (sort order is set by dragging rows in the table).
     *
     * @return array<int, mixed>
     */
    public static function status(): array
    {
        return [
            Toggle::make('is_active')
                ->label('Active')
                ->helperText('Inactive items are hidden from new enquiries but stay on old ones.')
                ->default(true),
        ];
    }

    public static function mm(string $name, ?string $label = null): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->integer()
            ->minValue(0)
            ->suffix('mm');
    }

    /**
     * Length typed in centimetres (one decimal) but stored in whole millimetres.
     */
    public static function cm(string $name, ?string $label = null): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->step(0.1)
            ->suffix('cm')
            ->formatStateUsing(fn ($state) => $state === null || $state === '' ? null : rtrim(rtrim(number_format(((int) $state) / 10, 1, '.', ''), '0'), '.'))
            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (int) round(((float) $state) * 10));
    }

    public static function percent(string $name, ?string $label = null): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->step(0.01)
            ->suffix('%');
    }

    public static function factor(string $name, ?string $label = null): TextInput
    {
        return TextInput::make($name)
            ->label($label ?? 'Price factor')
            ->numeric()
            ->minValue(0)
            ->step(0.001)
            ->default(1)
            ->prefix('×')
            ->helperText('1.000 = no change, 1.200 = +20%, 0.900 = −10%.');
    }
}
