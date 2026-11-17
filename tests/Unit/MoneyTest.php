<?php

use App\Support\Money;

test('formats paise with Indian digit grouping', function (int $paise, string $expected) {
    expect(Money::format($paise))->toBe($expected);
})->with([
    [0, '₹0.00'],
    [5, '₹0.05'],
    [99900, '₹999.00'],
    [100000, '₹1,000.00'],
    [12345600, '₹1,23,456.00'],
    [1234567890, '₹1,23,45,678.90'],
    [-250050, '-₹2,500.50'],
]);

test('formats without the rupee symbol', function () {
    expect(Money::format(12345600, withSymbol: false))->toBe('1,23,456.00');
});

test('converts rupees to paise rounding half up', function () {
    expect(Money::toPaise('1234.5'))->toBe(123450)
        ->and(Money::toPaise(10))->toBe(1000)
        ->and(Money::toPaise('0.005'))->toBe(1)
        ->and(Money::toPaise('0.004'))->toBe(0);
});

test('converts paise back to a rupee string', function () {
    expect(Money::toRupees(123450))->toBe('1234.50');
});
