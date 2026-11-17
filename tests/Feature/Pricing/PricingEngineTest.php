<?php

use App\Domain\Pricing\GstCalculator;
use App\Domain\Pricing\LineConfig;
use App\Domain\Pricing\PricingEngine;
use App\Models\Artwork;
use App\Models\ComplexityLevel;
use App\Models\Finishing;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\Substrate;
use App\Models\Tax;
use App\Settings\CompanySettings;
use App\Settings\PricingSettings;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $rules = app(PricingSettings::class);
    $rules->default_markup_percent = 50;
    $rules->labour_fixed_per_piece = 20_000;
    $rules->labour_per_perimeter_m = 10_000;
    $rules->rebate_allowance_mm = 2;
    $rules->rounding = 'none';
    $rules->quantity_breaks = [['min_qty' => 10, 'discount_percent' => 10.0]];
    $rules->rush_fee_percent = 15;
    $rules->rush_threshold_days = 7;
    $rules->estimate_range_percent = 10;
    $rules->commission_price_per_m2 = 1_000_000;
    $this->engine = new PricingEngine($rules);

    $this->substrate = Substrate::create(['name' => 'Paper', 'cost_per_m2' => 100_000, 'cost_per_perimeter_m' => 0]);
    $this->frame = Frame::create([
        'code' => 'F1', 'name' => 'Oak', 'profile_width_mm' => 20, 'profile_depth_mm' => 30, 'colour_hex' => '#AA8855',
        'finish' => 'matte', 'material' => 'wood', 'price_per_m' => 50_000, 'waste_percent' => 10,
    ]);
    $this->mat = Mat::create(['code' => 'M1', 'name' => 'White', 'colour_hex' => '#FFFFFF', 'default_border_mm' => 50, 'cost_per_m2' => 20_000, 'cut_fee' => 5_000]);
    $this->glazing = Glazing::create(['name' => 'Glass', 'material' => 'glass', 'reflection_level' => 'standard', 'cost_per_m2' => 30_000]);
    $this->finishing = Finishing::create(['name' => 'Wire', 'pricing_type' => 'fixed', 'cost' => 10_000]);
    $this->artwork = Artwork::create(['code' => 'A1', 'title' => 'Sunrise', 'type' => 'print_open_edition', 'pricing_model' => 'fixed_per_size']);
    $this->artwork->sizePrices()->create(['width_mm' => 500, 'height_mm' => 700, 'price' => 1_000_000]);
});

function framedLine(array $overrides = []): LineConfig
{
    $t = test();

    return LineConfig::fromArray([
        'source_type' => 'catalog',
        'artwork_id' => $t->artwork->id,
        'size_reference' => 'image',
        'width_mm' => 500,
        'height_mm' => 700,
        'quantity' => 1,
        'substrate_id' => $t->substrate->id,
        'frame_id' => $t->frame->id,
        'mat_id' => $t->mat->id,
        'glazing_id' => $t->glazing->id,
        'finishing_ids' => [$t->finishing->id],
        ...$overrides,
    ]);
}

it('prices a framed catalog print component by component', function () {
    $r = $this->engine->price(framedLine());

    // 500×700 picture + 50 mm mat = 600×800, + 2 mm allowance = 604×804 rebate, perimeter 2816 mm.
    expect($r['dimensions'])->toMatchArray([
        'mat_outer_w' => 600, 'mat_outer_h' => 800, 'rebate_w' => 604, 'rebate_h' => 804,
        'outer_w' => 644, 'outer_h' => 844, 'perimeter_mm' => 2816,
    ]);

    $sell = collect($r['components'])->pluck('sell', 'key');
    expect($sell['artwork'])->toBe(1_000_000)           // catalog price, no markup
        ->and($sell['substrate'])->toBe(52_500)         // 0.35 m² × ₹1,000 = ₹350 cost, +50%
        ->and($sell['frame'])->toBe(245_520)            // (2.816 + 8×0.02) m × 1.1 waste × ₹500 = ₹1,636.80 cost
        ->and($sell['mat'])->toBe(21_900)               // 0.48 m² × ₹200 + ₹50 cut
        ->and($sell['glazing'])->toBe(21_852)           // 0.4856 m² × ₹300
        ->and($sell['finishing_'.$this->finishing->id])->toBe(15_000)
        ->and($sell['labour'])->toBe(72_240);           // ₹200 + 2.816 m × ₹100

    expect($r['unit_cost'])->toBe(286_008)
        ->and($r['unit_price'])->toBe(1_429_012)
        ->and($r['line_total'])->toBe(1_429_012)
        ->and($r['complete'])->toBeTrue()
        ->and($r['estimate']['unit_low'])->toBe(1_286_111)
        ->and($r['estimate']['unit_high'])->toBe(1_571_913);
});

it('applies quantity breaks and the rush fee', function () {
    $r = $this->engine->price(framedLine(['quantity' => 10]));
    expect($r['quantity_discount_percent'])->toBe(10.0)
        ->and($r['unit_price'])->toBe((int) round(1_429_012 * 0.9))
        ->and($r['line_total'])->toBe((int) round(1_429_012 * 0.9) * 10);

    $today = Carbon::parse('2026-11-19');
    $rushed = $this->engine->price(framedLine(), Carbon::parse('2026-11-22'), $today);
    $relaxed = $this->engine->price(framedLine(), Carbon::parse('2026-12-30'), $today);
    expect($rushed['rush_fee_percent'])->toBe(15.0)
        ->and($rushed['unit_price'])->toBe((int) round(1_429_012 * 1.15))
        ->and($relaxed['rush_fee_percent'])->toBe(0.0);
});

it('works out the picture size from an outer size', function () {
    $r = $this->engine->price(framedLine(['size_reference' => 'outer', 'width_mm' => 644, 'height_mm' => 844]));

    expect($r['dimensions']['image_w'])->toBe(500)
        ->and($r['dimensions']['image_h'])->toBe(700)
        ->and($r['unit_price'])->toBe(1_429_012);
});

it('scales a catalog price when the size is not listed', function () {
    $r = $this->engine->price(framedLine(['width_mm' => 1000, 'height_mm' => 700, 'frame_id' => null, 'mat_id' => null, 'glazing_id' => null, 'finishing_ids' => []]));

    expect(collect($r['components'])->firstWhere('key', 'artwork')['sell'])->toBe(2_000_000);
});

it('gives a range for a new creation until the complexity is chosen', function () {
    ComplexityLevel::create(['name' => 'Simple', 'price_factor' => 1]);
    $detailed = ComplexityLevel::create(['name' => 'Detailed', 'price_factor' => 2]);
    $config = ['source_type' => 'custom_creation', 'artwork_id' => null, 'width_mm' => 1000, 'height_mm' => 1000, 'frame_id' => null, 'mat_id' => null, 'glazing_id' => null, 'finishing_ids' => []];

    $open = $this->engine->price(framedLine($config));
    // ₹10,000 artist fee (1 m² × ₹10,000) to ₹20,000, plus paper ₹1,000 + 50%.
    expect($open['complete'])->toBeFalse()
        ->and($open['estimate']['unit_low'])->toBe(1_000_000 + 150_000)
        ->and($open['estimate']['unit_high'])->toBe(2_000_000 + 150_000);

    $set = $this->engine->price(framedLine([...$config, 'complexity_level_id' => $detailed->id]));
    expect($set['complete'])->toBeTrue()->and($set['unit_price'])->toBe(2_150_000);
});

it('only charges framing for the customer\'s own artwork', function () {
    $r = $this->engine->price(framedLine(['source_type' => 'own_artwork', 'artwork_id' => null, 'own' => ['service' => 'frame_only']]));

    expect(collect($r['components'])->pluck('key'))->not->toContain('artwork')->not->toContain('substrate')->toContain('frame');
});

it('flags choices still to be advised', function () {
    $r = $this->engine->price(framedLine(['frame_id' => null, 'frame_advice_requested' => true, 'substrate_id' => null]));

    expect($r['complete'])->toBeFalse()->and($r['pending'])->toContain('Frame to be advised', 'Surface to be advised');
});

it('rounds to the configured step', function () {
    app(PricingSettings::class)->rounding = '10';
    $engine = new PricingEngine(app(PricingSettings::class));

    expect($engine->round(1_429_012))->toBe(1_429_000)->and($engine->round(1_429_600))->toBe(1_430_000);
});

it('splits GST into CGST + SGST within the state and IGST across states', function () {
    Tax::create(['name' => 'GST 18%', 'rate_percent' => 18, 'is_default' => true]);
    $company = app(CompanySettings::class);
    $company->state_code = '27';
    $gst = new GstCalculator($company);

    $local = $gst->calculate(100_001, '27');
    expect($local)->toMatchArray(['intra_state' => true, 'tax' => 18_000, 'cgst' => 9_000, 'sgst' => 9_000, 'igst' => 0, 'grand_total' => 118_001]);

    $other = $gst->calculate(100_000, '29');
    expect($other)->toMatchArray(['intra_state' => false, 'cgst' => 0, 'sgst' => 0, 'igst' => 18_000, 'grand_total' => 118_000]);

    expect($gst->calculate(100_000, '29', exempt: true)['tax'])->toBe(0);
});
