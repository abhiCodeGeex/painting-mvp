<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Quote lifecycle (spec §8). Approved / rejected / changes requested are set by the customer (M5).
 */
enum QuoteStatus: string implements HasColor, HasLabel
{
    use HasOptions;

    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Viewed => 'Viewed',
            self::ChangesRequested => 'Changes requested',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Superseded => 'Superseded',
            self::Expired => 'Expired',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent, self::Viewed => 'info',
            self::ChangesRequested => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Superseded, self::Expired => 'gray',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
