<?php

use App\Domain\Customers\CustomerAccounts;
use App\Enums\EnquiryStatus;
use App\Enums\Role;
use App\Filament\Resources\Sales\Customers\Pages\EditCustomer;
use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Filament\Resources\Sales\Enquiries\Pages\ListEnquiries;
use App\Filament\Resources\Sales\Enquiries\Pages\ViewEnquiry;
use App\Models\Artwork;
use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\EnquirySource;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Medium;
use App\Models\State;
use App\Models\StatusHistory;
use App\Models\Substrate;
use App\Models\User;
use App\Notifications\EnquirySubmitted;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
    $this->seed([StateSeeder::class, MasterDataSeeder::class, DemoCatalogSeeder::class]);
    Filament::setCurrentPanel('admin');

    $this->admin = User::factory()->staff()->create(['name' => 'Asha Admin']);
    $this->admin->assignRole(Role::SuperAdmin->value);
});

function staffEnquiryCustomer(string $email): Customer
{
    $customer = app(CustomerAccounts::class)->create([
        'name' => 'Buyer '.$email, 'email' => $email, 'password' => 'password', 'type' => 'individual', 'phone' => '9876543210',
    ], verified: true);

    $customer->addresses()->create([
        'kind' => 'delivery', 'line1' => '1 MG Road', 'city' => 'Bengaluru', 'pin_code' => '560001',
        'state_id' => State::where('gst_code', '29')->value('id'), 'is_default' => true,
    ]);

    return $customer;
}

function staffCatalogLine(): array
{
    return [
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
}

test('staff raise an enquiry for a customer from the admin inbox', function () {
    $customer = staffEnquiryCustomer('raise@example.com');
    $phone = EnquirySource::where('name', 'Phone')->value('id');
    $this->actingAs($this->admin);

    Livewire::test(ListEnquiries::class)
        ->callAction('raiseEnquiry', data: ['customer_id' => $customer->id, 'source_id' => $phone, 'project_name' => 'Office lobby'])
        ->assertHasNoActionErrors()
        ->assertRedirect(route('portal.enquiries.edit', $customer->enquiries()->firstOrFail()));

    $enquiry = $customer->enquiries()->firstOrFail();
    expect($enquiry->status)->toBe(EnquiryStatus::Draft)
        ->and($enquiry->customer_id)->toBe($customer->id)
        ->and($enquiry->project_name)->toBe('Office lobby')
        ->and($enquiry->source_id)->toBe($phone)
        ->and($enquiry->delivery_address_id)->toBe($customer->addresses()->value('id'));

    // The staff member has no customer profile of their own, and none is created.
    expect($this->admin->customer()->exists())->toBeFalse();
});

test('staff raise an enquiry from the customer page', function () {
    $customer = staffEnquiryCustomer('page@example.com');
    $this->actingAs($this->admin);

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->callAction('raiseEnquiry', data: [])
        ->assertHasNoActionErrors();

    expect($customer->enquiries()->count())->toBe(1);
});

test('staff edit the draft in the portal editor, add an artwork and send it for the customer', function () {
    $customer = staffEnquiryCustomer('send@example.com');
    $enquiry = $customer->enquiries()->create(['project_name' => 'Draft', 'delivery_address_id' => $customer->addresses()->value('id')]);
    $this->actingAs($this->admin);

    $this->get(route('portal.enquiries.edit', $enquiry))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('enquiries/edit')
            ->where('staff.customer_name', $customer->displayName())
            ->where('staff.admin_url', EnquiryResource::getUrl('view', ['record' => $enquiry], panel: 'admin'))
            ->has('addresses', 1));

    // Address added in the editor belongs to the customer, not to the staff member.
    $this->post(route('portal.enquiries.addresses.store', $enquiry), [
        'kind' => 'delivery', 'line1' => '2 Park Street', 'city' => 'Kolkata', 'pin_code' => '700016',
        'state_id' => State::where('gst_code', '19')->value('id') ?? State::value('id'),
    ])->assertSessionHasNoErrors();
    expect($customer->addresses()->count())->toBe(2);

    $this->put(route('portal.enquiries.update', $enquiry), [
        'project_name' => 'Phone order', 'project_type' => 'home', 'preferred_contact' => 'phone',
        'delivery_address_id' => $customer->addresses()->value('id'),
    ])->assertSessionHasNoErrors();

    $this->post(route('portal.enquiries.lines.store', $enquiry), staffCatalogLine())->assertSessionHasNoErrors();
    expect($enquiry->lines()->count())->toBe(1);

    $this->postJson(route('portal.enquiries.estimate', $enquiry), staffCatalogLine())->assertOk();

    $this->post(route('portal.enquiries.submit', $enquiry))
        ->assertRedirect(EnquiryResource::getUrl('view', ['record' => $enquiry], panel: 'admin'));

    $enquiry->refresh();
    expect($enquiry->status)->toBe(EnquiryStatus::Submitted)
        ->and($enquiry->project_name)->toBe('Phone order');

    $history = StatusHistory::where('subject_id', $enquiry->id)->where('to', 'submitted')->firstOrFail();
    expect($history->user_id)->toBe($this->admin->id)
        ->and($history->note)->toContain('on behalf of the customer');

    Notification::assertSentTo($customer->user, EnquirySubmitted::class);

    // Still editable for staff before it is quoted; the admin view offers the editor.
    $this->get(route('portal.enquiries.edit', $enquiry))->assertOk();
    Livewire::test(ViewEnquiry::class, ['record' => $enquiry->getRouteKey()])->assertActionVisible('openEditor');

    // Staff who open the portal page of the enquiry are sent to the admin screen.
    $this->get(route('portal.enquiries.show', $enquiry))->assertRedirect(EnquiryResource::getUrl('view', ['record' => $enquiry], panel: 'admin'));
});

test('staff cannot change a quoted enquiry in the editor', function () {
    $customer = staffEnquiryCustomer('quoted@example.com');
    $enquiry = $customer->enquiries()->create(['project_name' => 'Quoted']);
    $enquiry->forceFill(['status' => EnquiryStatus::Quoted])->save();
    $this->actingAs($this->admin);

    $this->get(route('portal.enquiries.edit', $enquiry))->assertRedirect(EnquiryResource::getUrl('view', ['record' => $enquiry], panel: 'admin'));
    $this->post(route('portal.enquiries.lines.store', $enquiry), staffCatalogLine())->assertForbidden();
});

test('other customers still cannot open or change an enquiry raised by staff', function () {
    $owner = staffEnquiryCustomer('owner@example.com');
    $other = staffEnquiryCustomer('other@example.com');
    $enquiry = $owner->enquiries()->create(['project_name' => 'Private']);
    $this->actingAs($other->user);

    $this->get(route('portal.enquiries.edit', $enquiry))->assertForbidden();
    $this->post(route('portal.enquiries.lines.store', $enquiry), staffCatalogLine())->assertForbidden();
    $this->post(route('portal.enquiries.addresses.store', $enquiry), [
        'kind' => 'delivery', 'line1' => 'x', 'city' => 'y', 'pin_code' => '560001', 'state_id' => State::value('id'),
    ])->assertForbidden();
    $this->post(route('portal.enquiries.submit', $enquiry))->assertForbidden();

    // The owner can still work on it.
    $this->actingAs($owner->user);
    $this->get(route('portal.enquiries.edit', $enquiry))->assertOk()->assertInertia(fn ($page) => $page->where('staff', null));
});

test('staff without enquiry permission or admin access cannot use the editor or the admin action', function () {
    $customer = staffEnquiryCustomer('perm@example.com');
    $enquiry = $customer->enquiries()->create(['project_name' => 'Draft']);

    // Staff who may only read enquiries.
    $reader = User::factory()->staff()->create();
    $role = SpatieRole::findOrCreate('enquiry_reader', 'web');
    $role->givePermissionTo(Permission::findOrCreate('ViewAny:Enquiry', 'web'), Permission::findOrCreate('View:Enquiry', 'web'));
    $reader->assignRole($role);
    $this->actingAs($reader);

    Livewire::test(ListEnquiries::class)->assertActionHidden('raiseEnquiry');
    $this->get(route('portal.enquiries.edit', $enquiry))->assertRedirect(EnquiryResource::getUrl('view', ['record' => $enquiry], panel: 'admin'));
    $this->post(route('portal.enquiries.lines.store', $enquiry), staffCatalogLine())->assertForbidden();
    $this->post(route('portal.enquiries.submit', $enquiry))->assertForbidden();

    // Deactivated staff are kept out of the portal editor entirely.
    $inactive = User::factory()->staff()->create(['is_active' => false]);
    $inactive->assignRole(Role::SuperAdmin->value);
    $this->actingAs($inactive);
    $this->get(route('portal.enquiries.edit', $enquiry))->assertRedirect('/admin');
    $this->post(route('portal.enquiries.lines.store', $enquiry), staffCatalogLine())->assertRedirect('/admin');

    // Staff are still sent to the admin panel from the rest of the portal.
    $this->actingAs($this->admin);
    $this->get(route('portal.enquiries.index'))->assertRedirect('/admin');
    $this->post(route('portal.enquiries.store'))->assertRedirect('/admin');

    // Customers cannot reach the admin action at all.
    $this->actingAs($customer->user);
    $this->get(EnquiryResource::getUrl('index'))->assertForbidden();
    expect($enquiry->lines()->count())->toBe(0);
});
