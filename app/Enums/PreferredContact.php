<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum PreferredContact: string implements HasLabel
{
    use HasOptions;

    case Email = 'email';
    case Phone = 'phone';
    case WhatsApp = 'whatsapp';

    public function getLabel(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Phone => 'Phone',
            self::WhatsApp => 'WhatsApp',
        };
    }
}
