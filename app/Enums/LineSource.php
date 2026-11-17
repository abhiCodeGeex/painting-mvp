<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum LineSource: string implements HasLabel
{
    use HasOptions;

    case Catalog = 'catalog';
    case CustomCreation = 'custom_creation';
    case OwnArtwork = 'own_artwork';

    public function getLabel(): string
    {
        return match ($this) {
            self::Catalog => 'From our catalog',
            self::CustomCreation => 'New custom creation',
            self::OwnArtwork => 'My own artwork',
        };
    }
}
