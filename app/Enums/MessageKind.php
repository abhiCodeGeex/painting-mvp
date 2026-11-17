<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasLabel;

enum MessageKind: string implements HasLabel
{
    use HasOptions;

    case Message = 'message';
    case Question = 'question';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Message => 'Message',
            self::Question => 'Question',
            self::System => 'System',
        };
    }
}
