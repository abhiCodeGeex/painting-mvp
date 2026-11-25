<?php

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Pricing\LineConfig;
use App\Domain\Pricing\PricingEngine;
use App\Models\Artwork;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\Medium;
use App\Models\Substrate;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
    $this->seed([StateSeeder::class, MasterDataSeeder::class, DemoCatalogSeeder::class]);

    $this->customer = app(CustomerAccounts::class)->create([
        'name' => 'Uploader', 'email' => 'uploader@example.com', 'password' => 'password', 'type' => 'individual', 'phone' => '9876543210',
    ], verified: true);
    $this->enquiry = $this->customer->enquiries()->create(['project_name' => 'Study']);
    $this->actingAs($this->customer->user);
});

function sourceLine(array $overrides = []): array
{
    return [
        'source_type' => 'catalog',
        'artwork_id' => Artwork::where('code', 'ART-0004')->value('id'),
        'unit' => 'cm',
        'size_reference' => 'image',
        'width_mm' => 600,
        'height_mm' => 400,
        'quantity' => 1,
        'frame_advice_requested' => true,
        'medium_id' => Medium::where('name', 'Giclée print')->value('id'),
        'substrate_id' => Substrate::where('name', 'Fine-art paper 310 gsm')->value('id'),
        ...$overrides,
    ];
}

test('files that do not belong to the chosen source are not stored', function () {
    $this->post(route('portal.enquiries.lines.store', $this->enquiry), sourceLine([
        'references' => [UploadedFile::fake()->image('sketch.jpg', 800, 600)],
        'artwork_photo' => UploadedFile::fake()->image('mine.jpg', 600, 800),
        'hi_res' => UploadedFile::fake()->image('big.jpg', 2000, 1500),
    ]))->assertSessionHasNoErrors();

    $line = $this->enquiry->lines()->firstOrFail();
    expect($line->getMedia('references'))->toHaveCount(0)
        ->and($line->getMedia('artwork_photo'))->toHaveCount(0)
        ->and($line->getMedia('hi_res'))->toHaveCount(0);
});

test('own artwork keeps the high-res file only when it is to be reproduced', function () {
    $own = fn (string $service) => sourceLine([
        'source_type' => 'own_artwork',
        'artwork_id' => null,
        'own' => ['service' => $service],
        'rights_confirmed' => true,
        'references' => [UploadedFile::fake()->image('sketch.jpg', 800, 600)],
        'artwork_photo' => UploadedFile::fake()->image('mine.jpg', 600, 800),
        'hi_res' => UploadedFile::fake()->image('big.jpg', 2000, 1500),
    ]);

    $this->post(route('portal.enquiries.lines.store', $this->enquiry), $own('frame_only'))->assertSessionHasNoErrors();
    $line = $this->enquiry->lines()->firstOrFail();
    expect($line->getMedia('artwork_photo'))->toHaveCount(1)
        ->and($line->getMedia('hi_res'))->toHaveCount(0)
        ->and($line->getMedia('references'))->toHaveCount(0);

    $this->post(route('portal.enquiries.lines.update', [$this->enquiry, $line]), $own('reproduce_and_frame'))->assertSessionHasNoErrors();
    expect($line->fresh()->getMedia('hi_res'))->toHaveCount(1)
        ->and($line->fresh()->getMedia('references'))->toHaveCount(0);
});

test('mat and glass are not charged without a frame', function () {
    $config = fn (array $o) => LineConfig::fromArray([
        ...sourceLine(),
        'frame_advice_requested' => false,
        'mat_id' => Mat::where('code', 'MAT-WHT')->value('id'),
        'glazing_id' => Glazing::where('name', 'Float glass 2 mm')->value('id'),
        ...$o,
    ]);
    $keys = fn (array $o) => collect(app(PricingEngine::class)->price($config($o))['components'])->pluck('key');

    expect($keys(['frame_id' => null]))->not->toContain('mat')->not->toContain('glazing')->not->toContain('labour')
        ->and($keys(['frame_id' => null, 'frame_advice_requested' => true]))->toContain('mat', 'glazing')
        ->and($keys(['frame_id' => Frame::where('code', 'FR-OAK-25')->value('id')]))->toContain('frame', 'mat', 'glazing');
});
