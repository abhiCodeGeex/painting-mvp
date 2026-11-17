<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum ArtworkType: string implements HasLabel
{
    use HasOptions;

    case Original = 'original';
    case PrintOpenEdition = 'print_open_edition';
    case PrintLimitedEdition = 'print_limited_edition';

    public function getLabel(): string
    {
        return match ($this) {
            self::Original => 'Original',
            self::PrintOpenEdition => 'Print — open edition',
            self::PrintLimitedEdition => 'Print — limited edition',
        };
    }
}
