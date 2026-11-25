<?php

use App\Enums\FrameFinish;
use App\Enums\FrameMaterial;
use App\Enums\Role;
use App\Filament\Pages\ManagePricingRules;
use App\Filament\Resources\Catalog\Artists\ArtistResource;
use App\Filament\Resources\Catalog\Artists\Pages\ManageArtists;
use App\Filament\Resources\Catalog\Artworks\ArtworkResource;
use App\Filament\Resources\Catalog\Categories\CategoryResource;
use App\Filament\Resources\Catalog\Mediums\MediumResource;
use App\Filament\Resources\Catalog\StandardSizes\StandardSizeResource;
use App\Filament\Resources\Catalog\Substrates\SubstrateResource;
use App\Filament\Resources\Framing\Finishings\FinishingResource;
use App\Filament\Resources\Framing\Frames\FrameResource;
use App\Filament\Resources\Framing\Frames\Pages\CreateFrame;
use App\Filament\Resources\Framing\Glazings\GlazingResource;
use App\Filament\Resources\Framing\Mats\MatResource;
use App\Filament\Resources\Framing\QualityTiers\QualityTierResource;
use App\Filament\Resources\Lists\EnquirySources\EnquirySourceResource;
use App\Filament\Resources\Lists\RejectionReasons\RejectionReasonResource;
use App\Filament\Resources\Lists\States\StateResource;
use App\Filament\Resources\Pricing\ComplexityLevels\ComplexityLevelResource;
use App\Filament\Resources\Pricing\ExtraCharges\ExtraChargeResource;
use App\Filament\Resources\Pricing\Taxes\Pages\ManageTaxes;
use App\Filament\Resources\Pricing\Taxes\TaxResource;
use App\Models\Artist;
use App\Models\Frame;
use App\Models\Medium;
use App\Models\Tax;
use App\Models\User;
use App\Settings\PricingSettings;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as RoleModel;

const MASTER_RESOURCES = [
    ArtworkResource::class, ArtistResource::class, CategoryResource::class, MediumResource::class,
    SubstrateResource::class, StandardSizeResource::class,
    FrameResource::class, MatResource::class, GlazingResource::class, FinishingResource::class, QualityTierResource::class,
    TaxResource::class, ExtraChargeResource::class, ComplexityLevelResource::class,
    StateResource::class, EnquirySourceResource::class, RejectionReasonResource::class,
];

function staffWithRole(Role $role): User
{
    $user = User::factory()->staff()->create();
    $user->assignRole($role->value);

    return $user;
}

beforeEach(function () {
    $this->seed([StateSeeder::class, MasterDataSeeder::class]);
    Filament::setCurrentPanel('admin');
});

test('super admin can open every master list and the pricing rules page', function () {
    $this->actingAs(staffWithRole(Role::SuperAdmin));

    foreach (MASTER_RESOURCES as $resource) {
        $this->get($resource::getUrl('index'))->assertOk();
    }

    $this->get(ManagePricingRules::getUrl())->assertOk();
    $this->get(FrameResource::getUrl('create'))->assertOk();
    $this->get(ArtworkResource::getUrl('create'))->assertOk();
    $this->get(FrameResource::getUrl('edit', ['record' => Frame::first()]))->assertOk();
});

test('creating a frame stores the rupee price in paise', function () {
    $this->actingAs(staffWithRole(Role::SuperAdmin));

    Livewire::test(CreateFrame::class)
        ->fillForm([
            'code' => 'FR-TEST-01',
            'name' => 'Test frame',
            'material' => FrameMaterial::Wood->value,
            'finish' => FrameFinish::Matte->value,
            'colour_hex' => '#123456',
            'profile_width_mm' => 25,
            'profile_depth_mm' => 30,
            'price_per_m' => '450.50',
            'waste_percent' => 10,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $frame = Frame::where('code', 'FR-TEST-01')->firstOrFail();

    expect($frame->price_per_m)->toBe(45050)
        ->and($frame->created_by)->toBe(auth()->id());
});

test('only one GST rate can be the default', function () {
    $this->actingAs(staffWithRole(Role::SuperAdmin));

    Livewire::test(ManageTaxes::class)
        ->callAction(CreateAction::class, data: [
            'name' => 'GST 28%',
            'rate_percent' => 28,
            'is_default' => true,
            'is_active' => true,
        ])
        ->assertHasNoFormErrors();

    expect(Tax::where('is_default', true)->pluck('name')->all())->toBe(['GST 28%']);
});

test('pricing rules can be saved', function () {
    $this->actingAs(staffWithRole(Role::SuperAdmin));

    Livewire::test(ManagePricingRules::class)
        ->assertFormFieldDoesNotExist('labour_fixed_per_piece')
        ->assertFormFieldDoesNotExist('labour_per_perimeter_m')
        ->assertFormFieldDoesNotExist('commission_price_per_m2')
        ->assertDontSee('Framing labour')
        ->fillForm([
            'default_markup_percent' => '45',
            'quantity_breaks' => [
                ['min_qty' => '20', 'discount_percent' => '7.5'],
                ['min_qty' => '5', 'discount_percent' => '3'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(PricingSettings::class)->refresh();

    expect($settings->default_markup_percent)->toBe(45.0)
        // Stored as JSON, so 3.0 comes back as 3: compare loosely.
        ->and($settings->quantity_breaks)->toEqual([
            ['min_qty' => 5, 'discount_percent' => 3.0],
            ['min_qty' => 20, 'discount_percent' => 7.5],
        ]);
});

test('pricing rules form loads the saved quantity breaks', function () {
    $this->actingAs(staffWithRole(Role::SuperAdmin));

    $state = Livewire::test(ManagePricingRules::class)->get('data');

    expect(array_values($state['quantity_breaks']))->toEqual([
        ['min_qty' => 10, 'discount_percent' => 5],
        ['min_qty' => 25, 'discount_percent' => 8],
        ['min_qty' => 50, 'discount_percent' => 12],
    ]);
});

test('a staff user without a role sees no masters', function () {
    $this->actingAs(User::factory()->staff()->create());

    $this->get(FrameResource::getUrl('index'))->assertForbidden();
});

test('archiving a master soft-deletes it and it can be restored', function () {
    $medium = Medium::first();

    $medium->delete();
    expect(Medium::find($medium->id))->toBeNull()
        ->and(Medium::withTrashed()->find($medium->id))->not->toBeNull();

    $medium->restore();
    expect(Medium::find($medium->id))->not->toBeNull();
});

test('changes to masters are written to the activity log', function () {
    $this->actingAs(staffWithRole(Role::SuperAdmin));

    $frame = Frame::first();
    $frame->update(['price_per_m' => 99900]);

    $this->assertDatabaseHas('activity_log', [
        'subject_type' => Frame::class,
        'subject_id' => $frame->id,
        'event' => 'updated',
    ]);
});

test('built-in roles cannot be deleted or renamed', function () {
    $superAdmin = RoleModel::findByName(Role::SuperAdmin->value, 'web');

    expect($superAdmin->delete())->toBeFalse();
    expect(RoleModel::where('name', Role::SuperAdmin->value)->exists())->toBeTrue();

    $superAdmin->name = 'renamed';
    expect($superAdmin->save())->toBeFalse();
    expect(RoleModel::where('name', Role::SuperAdmin->value)->exists())->toBeTrue();

    $custom = RoleModel::create(['name' => 'accounts', 'guard_name' => 'web']);
    expect($custom->delete())->toBeTrue();
});

test('master data seeders can run twice without duplicating rows', function () {
    $before = [Frame::count(), Medium::count(), Tax::count()];

    $this->seed([StateSeeder::class, MasterDataSeeder::class]);

    expect([Frame::count(), Medium::count(), Tax::count()])->toBe($before);
});

test('an artist has a fee per m², typed in rupees and stored in paise', function () {
    $this->actingAs(staffWithRole(Role::SuperAdmin));

    Livewire::test(ManageArtists::class)
        ->assertSee('Fee per m²')
        ->callAction(CreateAction::class, data: ['name' => 'Meera Rao', 'country' => 'IN', 'fee_per_m2' => '55000', 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(Artist::where('name', 'Meera Rao')->value('fee_per_m2'))->toBe(5_500_000);
});

test('complexity levels are no longer in the navigation', function () {
    expect(ComplexityLevelResource::shouldRegisterNavigation())->toBeFalse();
});
