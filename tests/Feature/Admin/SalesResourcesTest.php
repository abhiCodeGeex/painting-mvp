<?php

use App\Enums\EnquiryStatus;
use App\Enums\Role;
use App\Filament\Resources\Sales\Customers\CustomerResource;
use App\Filament\Resources\Sales\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Filament\Resources\Sales\Enquiries\Pages\ViewEnquiry;
use App\Models\Enquiry;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoEnquirySeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();

    $this->admin = User::factory()->staff()->create();
    $this->admin->assignRole(Role::SuperAdmin->value);

    $this->seed([StateSeeder::class, MasterDataSeeder::class, DemoCatalogSeeder::class, DemoEnquirySeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);
});

test('admin can open the enquiries inbox, an enquiry and the customers screens', function () {
    $enquiry = Enquiry::where('status', '!=', 'draft')->firstOrFail();

    $this->get(EnquiryResource::getUrl('index'))->assertOk()->assertSee($enquiry->reference);
    $this->get(EnquiryResource::getUrl('view', ['record' => $enquiry]))
        ->assertOk()
        ->assertSee('Lobby, behind reception')
        ->assertSee('walnut frame', false);

    $this->get(CustomerResource::getUrl('index'))->assertOk()->assertSee('Lotus Boutique Hotel');
    $this->get(CustomerResource::getUrl('edit', ['record' => $enquiry->customer]))->assertOk();
});

test('admin can reply from the enquiry screen', function () {
    $enquiry = Enquiry::where('status', EnquiryStatus::NeedsClarification)->firstOrFail();

    Livewire::test(ViewEnquiry::class, ['record' => $enquiry->getRouteKey()])
        ->callAction('reply', data: ['body' => 'Here is our suggestion.'])
        ->assertHasNoActionErrors();

    expect($enquiry->messages()->where('body', 'Here is our suggestion.')->exists())->toBeTrue();
});

test('admin can create a customer who gets an invitation email', function () {
    Livewire::test(CreateCustomer::class)
        ->fillForm([
            'type' => 'company',
            'company_name' => 'Acme Interiors',
            'name' => 'Priya Shah',
            'email' => 'priya@acme.test',
            'phone' => '+91 99887 66554',
            'send_invitation' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'priya@acme.test')->firstOrFail();
    expect($user->isCustomer())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->customer->company_name)->toBe('Acme Interiors');

    Notification::assertSentTo($user, ResetPassword::class);
});
