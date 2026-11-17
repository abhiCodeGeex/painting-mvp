<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum OwnArtworkService: string implements HasLabel
{
    use HasOptions;

    case FrameOnly = 'frame_only';
    case ReproduceAndFrame = 'reproduce_and_frame';

    public function getLabel(): string
    {
        return match ($this) {
            self::FrameOnly => 'Frame my artwork',
            self::ReproduceAndFrame => 'Reproduce (print) and frame',
        };
    }
}
