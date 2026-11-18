<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * What is being sold: the one-of-a-kind painting itself, or a print of it.
 */
enum ArtworkType: string implements HasColor, HasDescription, HasIcon, HasLabel
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

    public function getDescription(): string
    {
        return match ($this) {
            self::Original => 'The one-of-a-kind painting itself. Sold once.',
            self::PrintOpenEdition => 'Reproductions printed on demand, no limit on how many.',
            self::PrintLimitedEdition => 'A fixed number of numbered prints (set the edition size).',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Original => 'success',
            self::PrintOpenEdition => 'info',
            self::PrintLimitedEdition => 'warning',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::Original => Heroicon::OutlinedPaintBrush,
            self::PrintOpenEdition => Heroicon::OutlinedPrinter,
            self::PrintLimitedEdition => Heroicon::OutlinedHashtag,
        };
    }
}
