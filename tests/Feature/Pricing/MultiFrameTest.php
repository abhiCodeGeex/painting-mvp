<?php

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Pricing\FrameLayers;
use App\Domain\Pricing\LineConfig;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Quotes\QuoteBuilder;
use App\Models\Artwork;
use App\Models\EnquiryLine;
use App\Models\Frame;
use App\Models\Medium;
use App\Models\Substrate;
use App\Settings\PricingSettings;
use App\Support\FramedPreview;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Several frames on one picture: stacked layers (innermost first) and split layers (two mouldings).
 * Picture 500 × 700 mm, no mat, 2 mm rebate allowance → layer 1 opening (rebate) 504 × 704 mm.
 * Frame A: 20 mm, ₹500/m, 10% waste. Frame B: 30 mm, ₹800/m, no waste.
 */
beforeEach(function () {
    $rules = app(PricingSettings::class);
    $rules->default_markup_percent = 50;
    $rules->rebate_allowance_mm = 2;
    $rules->rounding = 'none';
    $this->rules = $rules;
    $this->engine = new PricingEngine($rules);

    $this->frameA = Frame::create([
        'code' => 'MF-A', 'name' => 'Blue ribbed', 'profile_width_mm' => 20, 'profile_depth_mm' => 25, 'colour_hex' => '#224488',
        'finish' => 'matte', 'material' => 'wood', 'price_per_m' => 50_000, 'waste_percent' => 10,
    ]);
    $this->frameB = Frame::create([
        'code' => 'MF-B', 'name' => 'Gold', 'profile_width_mm' => 30, 'profile_depth_mm' => 30, 'colour_hex' => '#C9A646',
        'finish' => 'metallic', 'material' => 'wood', 'price_per_m' => 80_000, 'waste_percent' => 0,
    ]);
    $this->artwork = Artwork::create(['code' => 'MF-ART', 'title' => 'Sea', 'type' => 'print_open_edition', 'pricing_model' => 'fixed_per_size']);
    $this->artwork->sizePrices()->create(['width_mm' => 500, 'height_mm' => 700, 'price' => 1_000_000]);
});

function multiFrameLine(array $overrides = []): LineConfig
{
    return LineConfig::fromArray([
        'source_type' => 'catalog',
        'artwork_id' => test()->artwork->id,
        'size_reference' => 'image',
        'width_mm' => 500,
        'height_mm' => 700,
        'quantity' => 1,
        ...$overrides,
    ]);
}

function multiFrameRows(array $priced): Illuminate\Support\Collection
{
    return collect($priced['components'])->keyBy('key');
}

it('prices two stacked frames, the second around the first', function () {
    $r = $this->engine->price(multiFrameLine(['frame_layers' => [
        ['frame_id' => $this->frameA->id, 'split' => null],
        ['frame_id' => $this->frameB->id, 'split' => null],
    ]]));

    // Layer 1: opening 504 × 704 → outer 544 × 744. Layer 2: opening 544 × 744 → outer 604 × 804.
    expect($r['dimensions'])->toMatchArray(['rebate_w' => 504, 'rebate_h' => 704, 'outer_w' => 604, 'outer_h' => 804, 'perimeter_mm' => 2416])
        ->and($r['dimensions']['layers'])->toBe([
            ['layer' => 1, 'opening_w' => 504, 'opening_h' => 704, 'profile_mm' => 20, 'outer_w' => 544, 'outer_h' => 744],
            ['layer' => 2, 'opening_w' => 544, 'opening_h' => 744, 'profile_mm' => 30, 'outer_w' => 604, 'outer_h' => 804],
        ]);

    $rows = multiFrameRows($r);
    expect($rows->has('frame'))->toBeFalse()
        // (2.416 + 8 × 0.02) m × 1.1 = 2.8336 m × ₹500
        ->and($rows['frame_1']['cost'])->toBe(141_680)
        ->and($rows['frame_1']['sell'])->toBe(212_520)
        ->and($rows['frame_1']['label'])->toBe('Frame 1 (inner): Blue ribbed')
        ->and($rows['frame_1']['measure'])->toBe('Rebate 50.4 × 70.4 cm → perimeter 2.416 m + mitres 8 × 20 mm = 2.576 m + 10% waste = 2.834 m')
        ->and($rows['frame_1']['rate'])->toBe('₹500.00/m (Blue ribbed, 20 mm profile)')
        // (2.576 + 8 × 0.03) m × ₹800, no waste
        ->and($rows['frame_2']['cost'])->toBe(225_280)
        ->and($rows['frame_2']['label'])->toBe('Frame 2 (around frame 1): Gold')
        ->and($rows['frame_2']['measure'])->toBe('Opening 54.4 × 74.4 cm → perimeter 2.576 m + mitres 8 × 30 mm = 2.816 m');
});

it('sizes a stacked piece from its outer size', function () {
    $r = $this->engine->price(multiFrameLine([
        'size_reference' => 'outer', 'width_mm' => 604, 'height_mm' => 804,
        'frame_layers' => [['frame_id' => $this->frameA->id], ['frame_id' => $this->frameB->id]],
    ]));

    expect($r['dimensions'])->toMatchArray(['image_w' => 500, 'image_h' => 700, 'outer_w' => 604, 'outer_h' => 804]);
});

it('splits a frame: top & bottom in one moulding, the sides in another', function () {
    $r = $this->engine->price(multiFrameLine(['frame_layers' => [
        ['frame_id' => $this->frameA->id, 'split' => ['frame_id' => $this->frameB->id, 'mode' => 'top_bottom_sides']],
    ]]));
    $rows = multiFrameRows($r);

    // The layer is as wide as the wider moulding (30 mm).
    expect($r['dimensions'])->toMatchArray(['outer_w' => 564, 'outer_h' => 764])
        // top & bottom: 2 × 504 + 4 × 20 = 1.088 m × 1.1 × ₹500
        ->and($rows['frame_1']['cost'])->toBe(59_840)
        ->and($rows['frame_1']['label'])->toBe('Frame 1 · top & bottom: Blue ribbed')
        ->and($rows['frame_1']['measure'])->toContain('top & bottom 2 × 50.4 cm + mitres 4 × 20 mm = 1.088 m')->toContain('wider moulding, 30 mm')
        // sides: 2 × 704 + 4 × 30 = 1.528 m × ₹800
        ->and($rows['frame_1_split']['cost'])->toBe(122_240)
        ->and($rows['frame_1_split']['label'])->toBe('Frame 1 · sides: Gold')
        ->and($rows['frame_1_split']['measure'])->toBe('Rebate 50.4 × 70.4 cm → sides 2 × 70.4 cm + mitres 4 × 30 mm = 1.528 m');
});

it('splits a frame into a top half and a bottom half', function () {
    $r = $this->engine->price(multiFrameLine(['frame_layers' => [
        ['frame_id' => $this->frameA->id, 'split' => ['frame_id' => $this->frameB->id, 'mode' => 'halves']],
    ]]));
    $rows = multiFrameRows($r);

    // Each half: 504 + 704 + 4 × profile.
    expect($rows['frame_1']['cost'])->toBe(70_840)          // 1.288 m × 1.1 × ₹500
        ->and($rows['frame_1']['label'])->toBe('Frame 1 · top half: Blue ribbed')
        ->and($rows['frame_1_split']['cost'])->toBe(106_240)  // 1.328 m × ₹800
        ->and($rows['frame_1_split']['label'])->toBe('Frame 1 · bottom half: Gold');
});

it('prices a plain frame_id exactly as one layer (lines saved before layers)', function () {
    $old = $this->engine->price(multiFrameLine(['frame_id' => $this->frameA->id]));
    $layered = $this->engine->price(multiFrameLine(['frame_layers' => [['frame_id' => $this->frameA->id, 'split' => null]]]));

    $row = multiFrameRows($old)['frame'];
    expect($row['cost'])->toBe(141_680)
        ->and($row['label'])->toBe('Frame: Blue ribbed')
        ->and($row['measure'])->toBe('Rebate 50.4 × 70.4 cm → perimeter 2.416 m + mitres 8 × 20 mm = 2.576 m + 10% waste = 2.834 m')
        ->and($old['dimensions'])->toMatchArray(['outer_w' => 544, 'outer_h' => 744])
        ->and($layered['components'])->toBe($old['components'])
        ->and($layered['line_total'])->toBe($old['line_total']);

    $line = new EnquiryLine(['frame_id' => $this->frameA->id]);
    expect($line->frameLayers())->toBe([['frame_id' => $this->frameA->id, 'split' => null]]);
});

it('normalises layers: at most three, unknown split modes dropped, frame id from layer 1', function () {
    $layers = FrameLayers::normalise(json_encode([
        ['frame_id' => 1, 'split' => ['frame_id' => 2, 'mode' => 'diagonal']],
        ['frame_id' => 2, 'split' => ['frame_id' => 1, 'mode' => 'halves']],
        ['frame_id' => 3], ['frame_id' => 4],
    ]), 9);

    expect($layers)->toBe([
        ['frame_id' => 1, 'split' => null],
        ['frame_id' => 2, 'split' => ['frame_id' => 1, 'mode' => 'halves']],
        ['frame_id' => 3, 'split' => null],
    ])->and(LineConfig::fromArray(['frame_id' => 9, 'frame_layers' => $layers])->frameId)->toBe(1);
});

describe('portal', function () {
    beforeEach(function () {
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        $this->seed([StateSeeder::class, MasterDataSeeder::class, DemoCatalogSeeder::class]);

        $this->customer = app(CustomerAccounts::class)->create([
            'name' => 'Framer', 'email' => 'framer@example.com', 'password' => 'password', 'type' => 'individual', 'phone' => '9876543210',
        ], verified: true);
        $this->enquiry = $this->customer->enquiries()->create(['project_name' => 'Layers']);
        $this->actingAs($this->customer->user);
    });

    $payload = fn (array $overrides = []): array => [
        'source_type' => 'catalog',
        'artwork_id' => Artwork::where('code', 'ART-0004')->value('id'),
        'unit' => 'cm',
        'size_reference' => 'image',
        'width_mm' => 600,
        'height_mm' => 400,
        'quantity' => 1,
        'medium_id' => Medium::where('name', 'Giclée print')->value('id'),
        'substrate_id' => Substrate::where('name', 'Fine-art paper 310 gsm')->value('id'),
        ...$overrides,
    ];

    it('saves stacked and split frames and keeps frame_id on the inner frame', function () use ($payload) {
        // The editor posts multipart data: the layers arrive as JSON text.
        $this->post(route('portal.enquiries.lines.store', $this->enquiry), $payload([
            'frame_id' => $this->frameB->id,
            'frame_layers' => json_encode([
                ['frame_id' => $this->frameA->id, 'split' => ['frame_id' => $this->frameB->id, 'mode' => 'halves']],
                ['frame_id' => $this->frameB->id, 'split' => null],
            ]),
        ]))->assertSessionHasNoErrors();

        $line = $this->enquiry->lines()->sole();
        expect($line->frame_id)->toBe($this->frameA->id)
            ->and($line->frameLayers())->toBe([
                ['frame_id' => $this->frameA->id, 'split' => ['frame_id' => $this->frameB->id, 'mode' => 'halves']],
                ['frame_id' => $this->frameB->id, 'split' => null],
            ])
            ->and(app(QuoteBuilder::class)->specText($line))->toContain('Frame: Blue ribbed + Gold around · Frame 1 split: top half / bottom half Gold');

        $rows = multiFrameRows($this->engine->price(LineConfig::fromLine($line)));
        expect($rows->keys()->all())->toContain('frame_1', 'frame_1_split', 'frame_2');

        // Admin preview: the outer frame wraps the inner one, whose halves are two colours.
        $preview = FramedPreview::forLine($line);
        expect($preview['outerFrameStyles'])->toHaveCount(1)
            ->and($preview['frameStyle'])->toContain('linear-gradient(to bottom, #224488 50%, #C9A646 50%)');
        $this->blade('<x-framed-preview :preview="$p" />', ['p' => $preview])->assertSee($preview['outerFrameStyles'][0], false);
    });

    it('saves a plain frame_id as one layer', function () use ($payload) {
        $this->post(route('portal.enquiries.lines.store', $this->enquiry), $payload(['frame_id' => $this->frameA->id]))
            ->assertSessionHasNoErrors();

        $line = $this->enquiry->lines()->sole();
        expect($line->frame_id)->toBe($this->frameA->id)
            ->and($line->frameLayers())->toBe([['frame_id' => $this->frameA->id, 'split' => null]]);
    });

    it('rejects more than three frames, an unknown split and an inactive frame', function () use ($payload) {
        $a = ['frame_id' => $this->frameA->id];
        $url = route('portal.enquiries.lines.store', $this->enquiry);

        $this->post($url, $payload(['frame_layers' => [$a, $a, $a, $a]]))->assertSessionHasErrors('frame_layers');
        $this->post($url, $payload(['frame_layers' => [['frame_id' => $this->frameA->id, 'split' => ['frame_id' => $this->frameB->id, 'mode' => 'diagonal']]]]))
            ->assertSessionHasErrors('frame_layers.0.split.mode');

        $this->frameB->update(['is_active' => false]);
        $this->post($url, $payload(['frame_layers' => [$a, ['frame_id' => $this->frameB->id]]]))->assertSessionHasErrors('frame_layers.1.frame_id');

        expect($this->enquiry->lines()->count())->toBe(0);
    });

    it('rejects a split that uses the same moulding as its frame', function () use ($payload) {
        $this->post(route('portal.enquiries.lines.store', $this->enquiry), $payload([
            'frame_layers' => json_encode([['frame_id' => $this->frameA->id, 'split' => ['frame_id' => $this->frameA->id, 'mode' => 'halves']]]),
        ]))->assertSessionHasErrors('frame_layers.0.split.frame_id');

        expect($this->enquiry->lines()->count())->toBe(0);
    });

    it('checks the frame around for size: its opening includes the frame inside', function () use ($payload) {
        // Picture 600 × 400; frame A (20 mm) makes the opening for the outer frame 640 × 440.
        $this->frameB->update(['max_inner_width_mm' => 620, 'max_inner_height_mm' => 620]);
        $this->post(route('portal.enquiries.lines.store', $this->enquiry), $payload([
            'frame_layers' => json_encode([['frame_id' => $this->frameA->id], ['frame_id' => $this->frameB->id]]),
        ]))->assertSessionHasErrors(['frame_layers.1.frame_id' => 'Gold is not available for this size.']);

        // Directly around the picture (600 × 400) it fits.
        $this->post(route('portal.enquiries.lines.store', $this->enquiry), $payload(['frame_id' => $this->frameB->id]))->assertSessionHasNoErrors();
    });

    it('keeps a frame and artwork that were deactivated after the line was saved', function () use ($payload) {
        $layers = [['frame_id' => $this->frameA->id, 'split' => null], ['frame_id' => $this->frameB->id, 'split' => null]];
        $this->post(route('portal.enquiries.lines.store', $this->enquiry), $payload(['frame_layers' => json_encode($layers)]))
            ->assertSessionHasNoErrors();
        $line = $this->enquiry->lines()->sole();

        $this->frameB->update(['is_active' => false]);
        Artwork::whereKey($line->artwork_id)->update(['is_active' => false]);

        // Other changes still save, with the same frames and artwork.
        $this->post(route('portal.enquiries.lines.update', [$this->enquiry, $line]), $payload(['frame_layers' => json_encode($layers), 'notes' => 'Hang low']))
            ->assertSessionHasNoErrors();
        expect($line->fresh()->notes)->toBe('Hang low');

        // But a new line can't pick them.
        $this->post(route('portal.enquiries.lines.store', $this->enquiry), $payload(['frame_layers' => json_encode($layers)]))
            ->assertSessionHasErrors(['artwork_id', 'frame_layers.1.frame_id']);
    });

    it('prices stacked frames in the live estimate', function () use ($payload) {
        $response = $this->postJson(route('portal.enquiries.estimate', $this->enquiry), $payload([
            'frame_id' => $this->frameA->id,
            'frame_layers' => [['frame_id' => $this->frameA->id, 'split' => null], ['frame_id' => $this->frameB->id, 'split' => null]],
        ]))->assertOk();

        expect($response->json())->not->toBeEmpty();
    });
});
