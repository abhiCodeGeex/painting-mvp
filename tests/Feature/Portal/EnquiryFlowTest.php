<?php

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Enquiries\EnquiryWorkflow;
use App\Domain\Quotes\QuoteBuilder;
use App\Enums\EnquiryStatus;
use App\Enums\Role;
use App\Http\Presenters\EnquiryPresenter;
use App\Models\Artwork;
use App\Models\Customer;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Medium;
use App\Models\State;
use App\Models\Substrate;
use App\Models\User;
use App\Notifications\EnquirySubmitted;
use App\Notifications\EnquirySubmittedForStaff;
use App\Notifications\NewEnquiryMessage;
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

    $this->admin = User::factory()->staff()->create();
    $this->admin->assignRole(Role::SuperAdmin->value);
});

function makeCustomer(string $email = 'buyer@example.com'): Customer
{
    return app(CustomerAccounts::class)->create([
        'name' => 'Buyer', 'email' => $email, 'password' => 'password', 'type' => 'individual', 'phone' => '9876543210',
    ], verified: true);
}

function addAddress(Customer $customer): int
{
    return $customer->addresses()->create([
        'kind' => 'delivery', 'line1' => '1 MG Road', 'city' => 'Bengaluru', 'pin_code' => '560001',
        'state_id' => State::where('gst_code', '29')->value('id'), 'is_default' => true,
    ])->id;
}

/** A valid catalog line: framed giclée print on paper. */
function catalogLine(array $overrides = []): array
{
    return [
        'source_type' => 'catalog',
        'artwork_id' => Artwork::where('code', 'ART-0004')->value('id'),
        'unit' => 'cm',
        'size_reference' => 'image',
        'width_mm' => 600,
        'height_mm' => 400,
        'quantity' => 2,
        'medium_id' => Medium::where('name', 'Giclée print')->value('id'),
        'substrate_id' => Substrate::where('name', 'Fine-art paper 310 gsm')->value('id'),
        'frame_id' => Frame::where('code', 'FR-OAK-25')->value('id'),
        'glazing_id' => Glazing::where('name', 'Float glass 2 mm')->value('id'),
        'wall_colour' => '#EDE8E1',
        ...$overrides,
    ];
}

test('a customer can create, fill and submit an enquiry', function () {
    $customer = makeCustomer();
    $addressId = addAddress($customer);
    $this->actingAs($customer->user);

    $this->post(route('portal.enquiries.store'))->assertRedirect();
    $enquiry = $customer->enquiries()->firstOrFail();
    expect($enquiry->status)->toBe(EnquiryStatus::Draft);

    $this->get(route('portal.enquiries.edit', $enquiry))->assertOk();

    $this->put(route('portal.enquiries.update', $enquiry), [
        'project_name' => 'Living room',
        'project_type' => 'home',
        'delivery_address_id' => $addressId,
        'preferred_contact' => 'whatsapp',
        'installation_needed' => true,
    ])->assertSessionHasNoErrors();

    $this->post(route('portal.enquiries.lines.store', $enquiry), catalogLine())->assertSessionHasNoErrors();
    expect($enquiry->lines()->count())->toBe(1);

    $this->post(route('portal.enquiries.submit', $enquiry))->assertRedirect(route('portal.enquiries.show', $enquiry));

    $enquiry->refresh();
    expect($enquiry->status)->toBe(EnquiryStatus::Submitted)
        ->and($enquiry->reference)->toBe('ENQ-'.now()->year.'-0001')
        ->and($enquiry->project_name)->toBe('Living room');

    Notification::assertSentTo($customer->user, EnquirySubmitted::class);
    Notification::assertSentTo($this->admin, EnquirySubmittedForStaff::class);

    $this->get(route('portal.enquiries.show', $enquiry))->assertOk();
});

test('only a delivery address that still exists can be the delivery address', function () {
    $customer = makeCustomer();
    $this->actingAs($customer->user);
    $enquiry = $customer->enquiries()->create(['project_name' => 'Hall']);
    $project = fn (?int $id) => ['project_name' => 'Hall', 'project_type' => 'home', 'preferred_contact' => 'email', 'delivery_address_id' => $id];

    // Added from the enquiry: always a delivery address, even if "billing" is posted.
    $this->post(route('portal.enquiries.addresses.store', $enquiry), [
        'kind' => 'billing', 'line1' => '9 Ring Road', 'city' => 'Pune', 'pin_code' => '411001',
        'state_id' => State::where('gst_code', '27')->value('id'),
    ])->assertSessionHasNoErrors();
    expect($customer->addresses()->sole()->kind->value)->toBe('delivery');

    // A billing address can't be chosen to deliver to.
    $billing = $customer->addresses()->create([
        'kind' => 'billing', 'line1' => '1 Bill St', 'city' => 'Pune', 'pin_code' => '411001', 'state_id' => State::where('gst_code', '27')->value('id'),
    ]);
    $this->put(route('portal.enquiries.update', $enquiry), $project($billing->id))->assertSessionHasErrors('delivery_address_id');

    // A delivery address deleted after it was chosen: the enquiry can't be sent with it.
    $delivery = $customer->addresses()->where('kind', 'delivery')->sole();
    $this->put(route('portal.enquiries.update', $enquiry), $project($delivery->id))->assertSessionHasNoErrors();
    $this->post(route('portal.enquiries.lines.store', $enquiry), catalogLine())->assertSessionHasNoErrors();
    $delivery->delete();
    $this->post(route('portal.enquiries.submit', $enquiry))->assertSessionHasErrors('delivery_address_id');
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Draft);
});

test('an enquiry cannot be submitted without artworks or an address', function () {
    $customer = makeCustomer();
    $this->actingAs($customer->user);
    $enquiry = $customer->enquiries()->create(['project_name' => 'Empty']);

    $this->post(route('portal.enquiries.submit', $enquiry))->assertSessionHasErrors(['lines', 'delivery_address_id']);
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Draft);
});

test('incompatible choices are rejected with a clear message', function () {
    $customer = makeCustomer();
    $this->actingAs($customer->user);
    $enquiry = $customer->enquiries()->create(['project_name' => 'Test']);

    // Stretched canvas cannot have glass.
    $this->post(route('portal.enquiries.lines.store', $enquiry), catalogLine([
        'substrate_id' => Substrate::where('name', 'Stretched canvas 38 mm')->value('id'),
        'frame_id' => null,
    ]))->assertSessionHasErrors(['glazing_id']);

    // Float glass is limited to 1.5 m².
    $this->post(route('portal.enquiries.lines.store', $enquiry), catalogLine(['width_mm' => 1500, 'height_mm' => 1200, 'frame_id' => null]))
        ->assertSessionHasErrors(['glazing_id']);

    expect($enquiry->lines()->count())->toBe(0);
});

test('a custom creation needs a description and a reference image', function () {
    $customer = makeCustomer();
    $this->actingAs($customer->user);
    $enquiry = $customer->enquiries()->create(['project_name' => 'Test']);

    $base = [...catalogLine(), 'source_type' => 'custom_creation', 'artwork_id' => null];

    $this->post(route('portal.enquiries.lines.store', $enquiry), $base)
        ->assertSessionHasErrors(['brief.description', 'brief.usage_rights']);

    $this->post(route('portal.enquiries.lines.store', $enquiry), [
        ...$base,
        'brief' => ['description' => 'A calm seascape', 'usage_rights' => 'private_display', 'palette' => ['#336699']],
    ])->assertSessionHasErrors(['references']);

    $this->post(route('portal.enquiries.lines.store', $enquiry), [
        ...$base,
        'brief' => ['description' => 'A calm seascape', 'usage_rights' => 'private_display', 'palette' => ['#336699']],
        'references' => [UploadedFile::fake()->image('sketch.jpg', 800, 600)],
    ])->assertSessionHasNoErrors();

    $line = $enquiry->lines()->firstOrFail();
    expect($line->custom_brief['description'])->toBe('A calm seascape')
        ->and($line->getMedia('references'))->toHaveCount(1)
        ->and($line->getFirstMedia('references')->disk)->toBe('local');
});

test('own-artwork photos are private to the owner and staff', function () {
    $owner = makeCustomer();
    $other = makeCustomer('other@example.com');
    $enquiry = $owner->enquiries()->create(['project_name' => 'Test']);

    $this->actingAs($owner->user)->post(route('portal.enquiries.lines.store', $enquiry), [
        ...catalogLine(),
        'source_type' => 'own_artwork',
        'artwork_id' => null,
        'own' => ['service' => 'frame_only'],
        'artwork_photo' => UploadedFile::fake()->image('mine.jpg', 600, 800),
        'rights_confirmed' => true,
    ])->assertSessionHasNoErrors();

    $media = $enquiry->lines()->firstOrFail()->getFirstMedia('artwork_photo');
    $url = route('media.show', $media);

    $this->actingAs($owner->user)->get($url)->assertOk();
    $this->actingAs($other->user)->get($url)->assertForbidden();
    $this->actingAs($this->admin)->get($url)->assertOk();
});

test('own artwork lines are named for what we do with them, and framing only claims no print medium', function () {
    $customer = makeCustomer();
    $enquiry = $customer->enquiries()->create(['project_name' => 'Mine']);
    $own = fn (string $service, ?string $title = null) => [
        ...catalogLine(),
        'source_type' => 'own_artwork',
        'artwork_id' => null,
        'title' => $title,
        'own' => ['service' => $service],
        'artwork_photo' => UploadedFile::fake()->image('mine.jpg', 600, 800),
        'rights_confirmed' => true,
    ];
    $this->actingAs($customer->user);
    foreach ([$own('frame_only'), $own('reproduce_and_frame'), $own('frame_only', 'Grandma\'s sketch')] as $line) {
        $this->post(route('portal.enquiries.lines.store', $enquiry), $line)->assertSessionHasNoErrors();
    }
    [$frameOnly, $reproduce, $titled] = $enquiry->lines()->orderBy('id')
        ->with(['artwork.artist', 'qualityTier', 'medium', 'substrate', 'frame', 'mat', 'glazing', 'finishings', 'media'])->get()->all();
    $medium = 'Giclée print';

    expect(EnquiryPresenter::line($frameOnly)['display_title'])->toBe('Your artwork (to frame)')
        ->and(EnquiryPresenter::line($frameOnly)['labels']['medium'])->toBeNull()
        ->and(EnquiryPresenter::line($reproduce)['display_title'])->toBe('Your artwork (to reproduce & frame)')
        ->and(EnquiryPresenter::line($reproduce)['labels']['medium'])->toBe($medium)
        ->and(EnquiryPresenter::line($titled)['display_title'])->toBe('Grandma\'s sketch')
        ->and(app(QuoteBuilder::class)->specText($frameOnly))->not->toContain($medium)
        ->and(app(QuoteBuilder::class)->specText($reproduce))->toContain($medium);
});

test('customers cannot see or change other customers\' enquiries', function () {
    $owner = makeCustomer();
    $intruder = makeCustomer('intruder@example.com');
    $enquiry = $owner->enquiries()->create(['project_name' => 'Private']);

    $this->actingAs($intruder->user);
    $this->get(route('portal.enquiries.edit', $enquiry))->assertForbidden();
    $this->get(route('portal.enquiries.show', $enquiry))->assertForbidden();
    $this->post(route('portal.enquiries.lines.store', $enquiry), catalogLine())->assertForbidden();
    $this->post(route('portal.enquiries.submit', $enquiry))->assertForbidden();
});

test('submitted enquiries can no longer be edited by the customer', function () {
    $customer = makeCustomer();
    $enquiry = $customer->enquiries()->create(['project_name' => 'Done', 'delivery_address_id' => addAddress($customer)]);
    $this->actingAs($customer->user)->post(route('portal.enquiries.lines.store', $enquiry), catalogLine());
    app(EnquiryWorkflow::class)->submit($enquiry, $customer->user);

    $this->post(route('portal.enquiries.lines.store', $enquiry), catalogLine())->assertForbidden();
    $this->get(route('portal.enquiries.edit', $enquiry))->assertRedirect(route('portal.enquiries.show', $enquiry));
});

test('question, reply and cancel follow the workflow', function () {
    $customer = makeCustomer();
    $enquiry = $customer->enquiries()->create(['project_name' => 'Flow', 'delivery_address_id' => addAddress($customer)]);
    $this->actingAs($customer->user)->post(route('portal.enquiries.lines.store', $enquiry), catalogLine());

    $workflow = app(EnquiryWorkflow::class);
    $workflow->submit($enquiry, $customer->user);
    $workflow->startReview($enquiry, $this->admin);
    expect($enquiry->fresh()->assigned_to)->toBe($this->admin->id);

    $workflow->askQuestion($enquiry, $this->admin, 'Which frame colour?');
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::NeedsClarification);
    Notification::assertSentTo($customer->user, NewEnquiryMessage::class);

    $this->actingAs($customer->user)
        ->post(route('portal.enquiries.messages.store', $enquiry), ['body' => 'Walnut please'])
        ->assertSessionHasNoErrors();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::UnderReview);
    Notification::assertSentTo($this->admin, NewEnquiryMessage::class);

    $this->post(route('portal.enquiries.cancel', $enquiry), ['reason' => 'Changed plans'])->assertRedirect();
    expect($enquiry->fresh()->status)->toBe(EnquiryStatus::Cancelled)
        ->and($enquiry->statusHistory()->pluck('to')->all())->toBe(['submitted', 'under_review', 'needs_clarification', 'under_review', 'cancelled']);
});

test('the portal never exposes internal notes', function () {
    $customer = makeCustomer();
    $enquiry = $customer->enquiries()->create([
        'project_name' => 'Secret test', 'delivery_address_id' => addAddress($customer), 'internal_notes' => 'VIP discount 20%',
    ]);
    $this->actingAs($customer->user)->post(route('portal.enquiries.lines.store', $enquiry), catalogLine());
    app(EnquiryWorkflow::class)->submit($enquiry, $customer->user);

    $this->get(route('portal.enquiries.show', $enquiry))->assertOk()->assertDontSee('VIP discount');
});

test('customers can manage their addresses', function () {
    $customer = makeCustomer();
    $this->actingAs($customer->user);

    $this->post(route('addresses.store'), [
        'kind' => 'delivery', 'line1' => '5 Park Street', 'city' => 'Kolkata', 'pin_code' => '700016',
        'state_id' => State::where('gst_code', '19')->value('id'),
    ])->assertSessionHasNoErrors();

    $address = $customer->addresses()->firstOrFail();
    expect($address->is_default)->toBeTrue();

    $this->post(route('addresses.store'), [
        'kind' => 'delivery', 'line1' => 'x', 'city' => 'y', 'pin_code' => '012345', 'state_id' => $address->state_id,
    ])->assertSessionHasErrors(['pin_code']);

    $this->get(route('addresses.index'))->assertOk();
});
