<?php

use App\Domain\Customers\CustomerAccounts;
use App\Http\Controllers\Portal\EnquiryLineController;
use App\Models\Artwork;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Medium;
use App\Models\Substrate;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->seed([StateSeeder::class, MasterDataSeeder::class, DemoCatalogSeeder::class]);

    $this->customer = app(CustomerAccounts::class)->create([
        'name' => 'Big Project', 'email' => 'big@example.com', 'password' => 'password', 'type' => 'individual', 'phone' => '9876543210',
    ], verified: true);
    $this->enquiry = $this->customer->enquiries()->create(['project_name' => 'Hotel']);

    // A valid framed print (helpers in other test files are not loaded when this file runs alone).
    $this->payload = [
        'source_type' => 'catalog',
        'artwork_id' => Artwork::where('code', 'ART-0004')->value('id'),
        'unit' => 'cm',
        'size_reference' => 'image',
        'width_mm' => 600,
        'height_mm' => 400,
        'quantity' => 1,
        'medium_id' => Medium::where('name', 'Giclée print')->value('id'),
        'substrate_id' => Substrate::where('name', 'Fine-art paper 310 gsm')->value('id'),
        'frame_id' => Frame::where('code', 'FR-OAK-25')->value('id'),
        'glazing_id' => Glazing::where('name', 'Float glass 2 mm')->value('id'),
    ];

    $this->actingAs($this->customer->user)
        ->post(route('portal.enquiries.lines.store', $this->enquiry), $this->payload)
        ->assertSessionHasNoErrors();
    $this->first = $this->enquiry->lines()->firstOrFail();
});

/** Fills the enquiry up to the limit without going through 50 HTTP requests. */
function fillEnquiryToLimit($test): void
{
    for ($i = $test->enquiry->lines()->count(); $i < EnquiryLineController::MAX_LINES; $i++) {
        $test->first->replicate()->fill(['position' => $i + 1])->save();
    }
}

test('an enquiry takes up to 50 artworks and refuses the 51st with a clear message', function () {
    fillEnquiryToLimit($this);
    expect($this->enquiry->lines()->count())->toBe(50);

    $this->post(route('portal.enquiries.lines.store', $this->enquiry), $this->payload)
        ->assertSessionHasErrors(['lines' => 'An enquiry can have up to 50 artworks. Please start a new enquiry for the rest.']);

    expect($this->enquiry->lines()->count())->toBe(50);
});

test('duplicating an artwork is refused once the enquiry is full', function () {
    $this->post(route('portal.enquiries.lines.duplicate', [$this->enquiry, $this->first]))->assertSessionHasNoErrors();
    expect($this->enquiry->lines()->count())->toBe(2);

    fillEnquiryToLimit($this);

    $this->post(route('portal.enquiries.lines.duplicate', [$this->enquiry, $this->first]))
        ->assertSessionHasErrors('lines');
    expect($this->enquiry->lines()->count())->toBe(50);
});

test('editing an artwork still works on a full enquiry', function () {
    fillEnquiryToLimit($this);

    $this->post(route('portal.enquiries.lines.update', [$this->enquiry, $this->first]), [...$this->payload, 'quantity' => 3])
        ->assertSessionHasNoErrors();
    expect($this->first->fresh()->quantity)->toBe(3);
});

test('the live estimate is rate limited', function () {
    $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'portal.enquiries.estimate');

    expect($route->gatherMiddleware())->toContain('throttle:120,1');
});
