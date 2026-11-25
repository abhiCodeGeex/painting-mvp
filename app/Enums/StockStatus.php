<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Stock level of an item: OK, Low (under its threshold, 20 by default) or Out (nothing left).
 */
enum StockStatus: string implements HasColor, HasIcon, HasLabel
{
    use HasOptions;

    case Ok = 'ok';
    case Low = 'low';
    case Out = 'out';

    public static function for(float $quantity, float $threshold): self
    {
        return match (true) {
            $quantity <= 0 => self::Out,
            $quantity < $threshold => self::Low,
            default => self::Ok,
        };
    }

    /**
     * Higher = worse. Used to notify only when the level gets worse.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Low => 1,
            self::Out => 2,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Ok => 'In stock',
            self::Low => 'Low stock',
            self::Out => 'Out of stock',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Low => 'warning',
            self::Out => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Ok => 'heroicon-m-check-circle',
            self::Low => 'heroicon-m-exclamation-triangle',
            self::Out => 'heroicon-m-x-circle',
        };
    }
}
