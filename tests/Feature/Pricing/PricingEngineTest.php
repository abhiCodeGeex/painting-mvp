<?php

use App\Domain\Pricing\GstCalculator;
use App\Domain\Pricing\LineConfig;
use App\Domain\Pricing\PricingEngine;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\ComplexityLevel;
use App\Models\Finishing;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\Medium;
use App\Models\Substrate;
use App\Models\Tax;
use App\Settings\CompanySettings;
use App\Settings\PricingSettings;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $rules = app(PricingSettings::class);
    $rules->default_markup_percent = 50;
    $rules->rebate_allowance_mm = 2;
    $rules->rounding = 'none';
    $rules->quantity_breaks = [['min_qty' => 10, 'discount_percent' => 10.0]];
    $rules->rush_fee_percent = 15;
    $rules->rush_threshold_days = 7;
    $rules->estimate_range_percent = 10;
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
        ->and($sell)->not->toHaveKey('labour');         // labour is entered on the quote, not from the masters

    expect($r['unit_cost'])->toBe(237_848)
        ->and($r['unit_price'])->toBe(1_356_772)
        ->and($r['line_total'])->toBe(1_356_772)
        ->and($r['complete'])->toBeTrue()
        ->and($r['estimate']['unit_low'])->toBe(1_221_095)
        ->and($r['estimate']['unit_high'])->toBe(1_492_449);
});

it('applies quantity breaks and the rush fee', function () {
    $r = $this->engine->price(framedLine(['quantity' => 10]));
    expect($r['quantity_discount_percent'])->toBe(10.0)
        ->and($r['unit_price'])->toBe((int) round(1_356_772 * 0.9))
        ->and($r['line_total'])->toBe((int) round(1_356_772 * 0.9) * 10);

    $today = Carbon::parse('2026-11-19');
    $rushed = $this->engine->price(framedLine(), Carbon::parse('2026-11-22'), $today);
    $relaxed = $this->engine->price(framedLine(), Carbon::parse('2026-12-30'), $today);
    expect($rushed['rush_fee_percent'])->toBe(15.0)
        ->and($rushed['unit_price'])->toBe((int) round(1_356_772 * 1.15))
        ->and($relaxed['rush_fee_percent'])->toBe(0.0);
});

it('works out the picture size from an outer size', function () {
    $r = $this->engine->price(framedLine(['size_reference' => 'outer', 'width_mm' => 644, 'height_mm' => 844]));

    expect($r['dimensions']['image_w'])->toBe(500)
        ->and($r['dimensions']['image_h'])->toBe(700)
        ->and($r['unit_price'])->toBe(1_356_772);
});

it('scales a catalog price when the size is not listed', function () {
    $r = $this->engine->price(framedLine(['width_mm' => 1000, 'height_mm' => 700, 'frame_id' => null, 'mat_id' => null, 'glazing_id' => null, 'finishing_ids' => []]));

    expect(collect($r['components'])->firstWhere('key', 'artwork')['sell'])->toBe(2_000_000);
});

it('leaves a new creation pending until an artist is chosen, with the range across artists\' fees', function () {
    Artist::create(['name' => 'Asha', 'fee_per_m2' => 4_000_000]);
    $bina = Artist::create(['name' => 'Bina', 'fee_per_m2' => 8_000_000]);
    Artist::create(['name' => 'Retired', 'fee_per_m2' => 20_000_000, 'is_active' => false]); // not in the range
    Artist::create(['name' => 'No fee yet', 'fee_per_m2' => 0]);                              // not in the range
    $config = ['source_type' => 'custom_creation', 'artwork_id' => null, 'width_mm' => 1000, 'height_mm' => 1000, 'frame_id' => null, 'mat_id' => null, 'glazing_id' => null, 'finishing_ids' => []];

    $open = $this->engine->price(framedLine($config));
    // 1 m² × ₹40,000 to ₹80,000, plus paper ₹1,000 + 50%.
    expect($open['complete'])->toBeFalse()
        ->and($open['pending'])->toContain('Choose an artist')
        ->and(collect($open['components'])->pluck('key'))->not->toContain('artwork')
        ->and($open['estimate']['unit_low'])->toBe(4_000_000 + 150_000)
        ->and($open['estimate']['unit_high'])->toBe(8_000_000 + 150_000);

    $set = $this->engine->price(framedLine([...$config, 'artist_id' => $bina->id]));
    $artwork = collect($set['components'])->firstWhere('key', 'artwork');
    expect($set['complete'])->toBeTrue()
        ->and($set['pending'])->toBe([])
        ->and($set['unit_price'])->toBe(8_150_000)
        ->and($artwork)->toMatchArray(['label' => 'New artwork by Bina', 'sell' => 8_000_000, 'marked_up' => false])
        ->and($artwork['rate'])->toBe('₹80,000.00/m² (artist fee: Bina)');
});

it('prices a new creation as artist fee × area × medium × tier', function () {
    $artist = Artist::create(['name' => 'Bina', 'fee_per_m2' => 8_000_000]);
    $watercolour = Medium::create(['name' => 'Watercolour', 'kind' => 'hand_painted', 'price_factor' => 1.2]);

    $r = $this->engine->price(framedLine(['source_type' => 'custom_creation', 'artwork_id' => null, 'width_mm' => 1200, 'height_mm' => 900,
        'medium_id' => $watercolour->id, 'artist_id' => $artist->id, 'frame_id' => null, 'mat_id' => null, 'glazing_id' => null, 'finishing_ids' => []]));
    $artwork = collect($r['components'])->firstWhere('key', 'artwork');

    // 1.08 m² × ₹80,000 × 1.2 = ₹1,03,680.
    expect($artwork['sell'])->toBe(10_368_000)
        ->and($artwork['measure'])->toBe('120 × 90 cm = 1.08 m²')
        ->and($artwork['rate'])->toBe('₹80,000.00/m² (artist fee: Bina) × 1.2 (Watercolour)')
        ->and(collect($r['components'])->pluck('key'))->not->toContain('artist_labour');
});

it('prices a catalog artwork without a price of its own from its artist\'s fee', function () {
    $artist = Artist::create(['name' => 'Asha', 'fee_per_m2' => 6_000_000]);
    $watercolour = Medium::create(['name' => 'Watercolour', 'kind' => 'hand_painted', 'price_factor' => 1.2]);
    $unpriced = Artwork::create(['code' => 'A2', 'title' => 'Dusk', 'type' => 'original', 'pricing_model' => 'fixed_per_size', 'artist_id' => $artist->id]);

    $r = $this->engine->price(framedLine(['artwork_id' => $unpriced->id, 'medium_id' => $watercolour->id, 'frame_id' => null, 'mat_id' => null, 'glazing_id' => null, 'finishing_ids' => []]));
    $artwork = collect($r['components'])->firstWhere('key', 'artwork');

    // 0.35 m² × ₹60,000 × 1.2 = ₹25,200.
    expect($artwork['sell'])->toBe(2_520_000)
        ->and($artwork['label'])->toBe('Dusk')
        ->and($artwork['rate'])->toBe('₹60,000.00/m² (artist fee: Asha) × 1.2 (Watercolour)')
        ->and($r['complete'])->toBeTrue();
});

it('uses the artwork\'s own price and ignores the artist\'s fee when it has one', function () {
    $artist = Artist::create(['name' => 'Asha', 'fee_per_m2' => 6_000_000]);
    $this->artwork->update(['artist_id' => $artist->id]);

    $listed = collect($this->engine->price(framedLine())['components'])->firstWhere('key', 'artwork');
    expect($listed['sell'])->toBe(1_000_000)->and($listed['rate'])->not->toContain('artist fee');

    $perM2 = Artwork::create(['code' => 'A3', 'title' => 'Noon', 'type' => 'print_open_edition', 'pricing_model' => 'per_m2', 'price_per_m2' => 1_000_000, 'artist_id' => $artist->id]);
    $row = collect($this->engine->price(framedLine(['artwork_id' => $perM2->id]))['components'])->firstWhere('key', 'artwork');
    expect($row['sell'])->toBe(350_000)->and($row['rate'])->toContain('(artwork master)')->not->toContain('artist fee');
});

it('explains how each component was measured and which master rate was used', function () {
    $r = $this->engine->price(framedLine(['quantity' => 10]));
    $rows = collect($r['components'])->keyBy('key');

    expect($rows['artwork'])->toMatchArray(['measure' => '50 × 70 cm (listed size)', 'marked_up' => false, 'cost' => 0, 'amount' => 1_000_000])
        ->and($rows['artwork']['rate'])->toContain('₹10,000.00')
        ->and($rows['substrate'])->toMatchArray(['measure' => '50 × 70 cm = 0.35 m²', 'rate' => '₹1,000.00/m²', 'cost' => 35_000, 'marked_up' => true])
        ->and($rows['frame']['measure'])->toBe('Rebate 60.4 × 80.4 cm → perimeter 2.816 m + mitres 8 × 20 mm = 2.976 m + 10% waste = 3.274 m')
        ->and($rows['frame']['rate'])->toBe('₹500.00/m (Oak, 20 mm profile)')
        ->and($rows['mat'])->toMatchArray(['measure' => '60 × 80 cm (50 mm border) = 0.48 m²', 'rate' => '₹200.00/m² + ₹50.00 cutting'])
        ->and($rows['glazing']['measure'])->toBe('Rebate 60.4 × 80.4 cm = 0.4856 m²')
        ->and($rows)->not->toHaveKey('labour');

    expect($r['markup_percent'])->toBe(50.0)
        ->and($r['adjustments'])->toHaveCount(1)
        ->and($r['adjustments'][0]['key'])->toBe('quantity_discount')
        // Components add up to the subtotal, and subtotal + adjustments = unit price.
        ->and(array_sum(array_column($r['components'], 'amount')))->toBe($r['unit_sell'])
        ->and($r['unit_sell'] + array_sum(array_column($r['adjustments'], 'amount')))->toBe($r['unit_price'])
        ->and($r['unit_margin'])->toBe($r['unit_price'] - $r['unit_cost']);
});

it('shows rounding as its own adjustment', function () {
    app(PricingSettings::class)->rounding = '10';
    $r = (new PricingEngine(app(PricingSettings::class)))->price(framedLine());

    expect(collect($r['adjustments'])->firstWhere('key', 'rounding')['amount'])->toBe(1_357_000 - 1_356_772)
        ->and($r['unit_price'])->toBe(1_357_000);
});

it('takes no labour or complexity from the masters', function () {
    $rules = app(PricingSettings::class);
    $rules->labour_fixed_per_piece = 20_000;   // old settings, no longer used
    $rules->labour_per_perimeter_m = 10_000;
    ComplexityLevel::create(['name' => 'Complex', 'price_factor' => 2, 'artist_rate_per_m2' => 400_000, 'artist_minimum' => 100_000]);
    $artist = Artist::create(['name' => 'Bina', 'fee_per_m2' => 8_000_000]);

    $framed = (new PricingEngine($rules))->price(framedLine());
    $creation = (new PricingEngine($rules))->price(framedLine(['source_type' => 'custom_creation', 'artwork_id' => null, 'artist_id' => $artist->id]));

    expect(collect($framed['components'])->pluck('key'))->not->toContain('labour')->not->toContain('artist_labour')
        ->and(collect($creation['components'])->pluck('key'))->not->toContain('labour')->not->toContain('artist_labour')
        ->and(collect($creation['components'])->firstWhere('key', 'artwork')['sell'])->toBe(2_800_000); // 0.35 m² × ₹80,000, no complexity factor
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
