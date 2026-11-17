<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum ProjectType: string implements HasLabel
{
    use HasOptions;

    case Home = 'home';
    case Hotel = 'hotel';
    case Office = 'office';
    case Restaurant = 'restaurant';
    case Retail = 'retail';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Home => 'Home',
            self::Hotel => 'Hotel',
            self::Office => 'Office',
            self::Restaurant => 'Restaurant / cafe',
            self::Retail => 'Retail / showroom',
            self::Other => 'Other',
        };
    }
}
