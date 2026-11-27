<?php

use App\Domain\Quotes\QuoteBuilder;
use App\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Staff enter a margin on every quote line before sending (it starts empty): price = cost + labour + margin.
 */
function withMargin(Quote $quote, int $margin = 100_000): Quote
{
    foreach ($quote->lines()->whereNull('margin_amount')->get() as $line) {
        $price = (int) $line->unit_cost + (int) $line->labour_amount + $margin;
        $line->update(['margin_amount' => $margin, 'unit_price' => $price, 'calculated_unit_price' => $price]);
    }

    return app(QuoteBuilder::class)->recalculate($quote->fresh());
}
