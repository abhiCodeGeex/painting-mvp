<?php

namespace App\Enums\Concerns;

/**
 * For backed enums that implement Filament's HasLabel: value => label pairs.
 */
trait HasOptions
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
