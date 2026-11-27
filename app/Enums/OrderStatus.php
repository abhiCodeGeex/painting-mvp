<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Order lifecycle after a quotation is approved. Staff may set any status at any time; the token
 * payment moves Placed → Confirmed, and the last payment after delivery moves Delivered → Completed.
 */
enum OrderStatus: string implements HasColor, HasLabel
{
    use HasOptions;

    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case InProduction = 'in_production';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Placed => 'Placed — awaiting token',
            self::Confirmed => 'Confirmed',
            self::InProduction => 'In production',
            self::Dispatched => 'Dispatched',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Wording shown to customers in the portal. */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Placed => 'Waiting for your token payment',
            self::Confirmed => 'Confirmed',
            self::InProduction => 'Being made',
            default => $this->getLabel(),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Placed => 'warning',
            self::Confirmed, self::InProduction => 'info',
            self::Dispatched => 'primary',
            self::Delivered, self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }
}
