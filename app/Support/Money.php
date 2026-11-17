<?php

namespace App\Support;

use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;

/**
 * Money helpers. All amounts are stored as integer paise (INR × 100), see spec §7.4.
 */
final class Money
{
    public const CURRENCY = 'INR';

    /**
     * Format paise as Indian rupees with lakh/crore grouping, e.g. 12345600 → "₹1,23,456.00".
     */
    public static function format(int $paise, bool $withSymbol = true): string
    {
        $negative = $paise < 0;
        $paise = abs($paise);

        $rupees = intdiv($paise, 100);
        $fraction = str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);

        $digits = (string) $rupees;
        if (strlen($digits) > 3) {
            $lastThree = substr($digits, -3);
            $rest = substr($digits, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $digits = $rest.','.$lastThree;
        }

        return ($negative ? '-' : '').($withSymbol ? '₹' : '').$digits.'.'.$fraction;
    }

    /**
     * Convert a rupee amount (e.g. "1234.5" or 1234.5) to integer paise, rounding half up.
     */
    public static function toPaise(string|int|float $rupees): int
    {
        return BrickMoney::of((string) $rupees, self::CURRENCY, roundingMode: RoundingMode::HalfUp)
            ->getMinorAmount()
            ->toInt();
    }

    public static function toRupees(int $paise): string
    {
        return (string) BrickMoney::ofMinor($paise, self::CURRENCY)->getAmount();
    }
}
