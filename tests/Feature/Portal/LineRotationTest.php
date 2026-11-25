<?php

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Quotes\QuoteBuilder;
use App\Http\Presenters\EnquiryPresenter;
use App\Models\Artwork;
use App\Models\Medium;
use App\Models\Substrate;
use App\Support\FramedPreview;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
    $this->seed([StateSeeder::class, MasterDataSeeder::class, DemoCatalogSeeder::class]);

    $this->customer = app(CustomerAccounts::class)->create([
        'name' => 'Rotator', 'email' => 'rotator@example.com', 'password' => 'password', 'type' => 'individual', 'phone' => '9876543210',
    ], verified: true);
    $this->enquiry = $this->customer->enquiries()->create(['project_name' => 'Hallway']);
    $this->actingAs($this->customer->user);
});

function rotationLine(array $overrides = []): array
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

test('a line can be rotated and the rotation is persisted and presented', function () {
    $this->post(route('portal.enquiries.lines.store', $this->enquiry), rotationLine(['image_rotation' => 90]))
        ->assertSessionHasNoErrors();

    $line = $this->enquiry->lines()->firstOrFail();
    expect($line->image_rotation)->toBe(90)
        ->and(EnquiryPresenter::line($line)['image_rotation'])->toBe(90)
        ->and($line->sidesSwapped())->toBeTrue()
        ->and($line->rotationLabel())->toContain('Width and height swapped')
        ->and($line->printSizeLabel())->not->toBe($line->sizeLabel())
        ->and(app(QuoteBuilder::class)->specText($line))->toContain($line->printSizeLabel())
        ->and(app(QuoteBuilder::class)->specText($line))->toContain('Width and height swapped');

    // Admin preview: the box takes the swapped size and the upright picture is stretched into it (never turned).
    $preview = FramedPreview::forLine($line->fresh(), 200, withWall: false);
    expect($preview['rotation'])->toBe(90)
        ->and($preview['imageInnerStyle'])->toBeNull()
        ->and($preview['imageStyle'])->toContain('background-size:100% 100%')
        ->and($preview['imageStyle'])->not->toContain('rotate(');

    $this->post(route('portal.enquiries.lines.update', [$this->enquiry, $line]), rotationLine(['image_rotation' => 270]))
        ->assertSessionHasNoErrors();
    expect($line->fresh()->image_rotation)->toBe(270);

    // Omitted → back to upright.
    $this->post(route('portal.enquiries.lines.update', [$this->enquiry, $line]), rotationLine())
        ->assertSessionHasNoErrors();
    expect($line->fresh()->image_rotation)->toBe(0)
        ->and(EnquiryPresenter::line($line->fresh())['image_rotation'])->toBe(0)
        ->and($line->fresh()->rotationLabel())->toBeNull();
});

test('only quarter turns are accepted', function () {
    $this->post(route('portal.enquiries.lines.store', $this->enquiry), rotationLine(['image_rotation' => 45]))
        ->assertSessionHasErrors('image_rotation');
    expect($this->enquiry->lines()->count())->toBe(0);
});

test('the price estimate accepts and ignores the rotation', function () {
    $plain = $this->postJson(route('portal.enquiries.estimate', $this->enquiry), rotationLine())->assertOk()->json();
    $turned = $this->postJson(route('portal.enquiries.estimate', $this->enquiry), rotationLine(['image_rotation' => 90]))->assertOk()->json();

    expect($turned)->toEqual($plain);
});
