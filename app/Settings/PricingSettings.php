<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Pricing rules used by the pricing engine (spec §4.2 "Pricing rules", §7).
 * Money in paise, percentages as plain numbers (e.g. 35.0 = 35%).
 */
class PricingSettings extends Settings
{
    /** Unused: labour is now entered per quote line. Kept so old settings rows still load. */
    public int $labour_fixed_per_piece;

    /** Unused: labour is now entered per quote line. */
    public int $labour_per_perimeter_m;

    /** Markup applied to cost to get the selling price, in %. */
    public float $default_markup_percent;

    // Rows of ['min_qty' => int, 'discount_percent' => float], sorted by min_qty.
    // Deliberately no @var docblock: spatie/laravel-settings reads it to pick a cast.
    public array $quantity_breaks;

    public float $rush_fee_percent;

    /** Deadline closer than this many days triggers the rush fee. */
    public int $rush_threshold_days;

    /** One of App\Enums\Rounding values. */
    public string $rounding;

    /** ± range shown on the customer's indicative estimate, in %. */
    public float $estimate_range_percent;

    /** Mat overlaps the artwork by this much on each side (mm). */
    public int $mat_overlap_mm;

    /** Extra clearance added to the frame rebate on each side (mm). */
    public int $rebate_allowance_mm;

    /** Unused: the artist fee per m² is now set on each artist. */
    public int $commission_price_per_m2;

    /** Customer estimate while building an enquiry: exact | range | hidden. */
    public string $customer_estimate;

    public int $quote_validity_days;

    public string $default_lead_time;

    public string $default_payment_terms;

    public string $default_quote_terms;

    public static function group(): string
    {
        return 'pricing';
    }
}
