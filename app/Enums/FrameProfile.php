<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Cross-section shape of a frame moulding — drives the 3D preview (spec §10.1).
 */
enum FrameProfile: string implements HasDescription, HasLabel
{
    use HasOptions;

    case Flat = 'flat';
    case Rounded = 'rounded';
    case Scoop = 'scoop';
    case Stepped = 'stepped';
    case Ornate = 'ornate';
    case Floater = 'floater';

    public function getLabel(): string
    {
        return match ($this) {
            self::Flat => 'Flat (box)',
            self::Rounded => 'Rounded (half-round)',
            self::Scoop => 'Scoop (concave)',
            self::Stepped => 'Stepped',
            self::Ornate => 'Ornate (carved)',
            self::Floater => 'Floater (canvas floats inside)',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Flat => 'Square modern moulding.',
            self::Rounded => 'Soft rounded face.',
            self::Scoop => 'Face curves inwards towards the art.',
            self::Stepped => 'Two or three stepped levels.',
            self::Ornate => 'Classic carved moulding with beading.',
            self::Floater => 'L-shaped tray with a shadow gap around the canvas.',
        };
    }
}
