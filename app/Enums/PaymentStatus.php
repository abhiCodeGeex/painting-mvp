<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Due → (customer says they paid) Submitted → (staff check it) Received, or Not received.
 * Staff can also record a payment as received straight away.
 */
enum PaymentStatus: string implements HasColor, HasLabel
{
    use HasOptions;

    case Due = 'due';
    case Submitted = 'submitted';
    case Received = 'received';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Due => 'Due',
            self::Submitted => 'To check',
            self::Received => 'Received',
            self::Rejected => 'Not received',
        };
    }

    public function customerLabel(): string
    {
        return match ($this) {
            self::Due => 'To pay',
            self::Submitted => 'We are checking your payment',
            self::Received => 'Paid — thank you',
            self::Rejected => 'We could not find this payment',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Due => 'gray',
            self::Submitted => 'warning',
            self::Received => 'success',
            self::Rejected => 'danger',
        };
    }
}
