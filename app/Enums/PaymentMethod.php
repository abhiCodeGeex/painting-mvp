<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

/** How a customer paid (offline: staff confirm each payment). */
enum PaymentMethod: string implements HasLabel
{
    use HasOptions;

    case Upi = 'upi';
    case BankTransfer = 'bank_transfer';
    case Card = 'card';
    case Cash = 'cash';
    case Cheque = 'cheque';

    public function getLabel(): string
    {
        return match ($this) {
            self::Upi => 'UPI',
            self::BankTransfer => 'Bank transfer (NEFT / RTGS / IMPS)',
            self::Card => 'Card',
            self::Cash => 'Cash',
            self::Cheque => 'Cheque',
        };
    }
}
