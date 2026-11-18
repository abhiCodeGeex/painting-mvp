<?php

use App\Enums\Role;
use App\Filament\Resources\Catalog\StandardSizes\Pages\ManageStandardSizes;
use App\Filament\Resources\Framing\Finishings\FinishingResource;
use App\Filament\Resources\Framing\Frames\FrameResource;
use App\Http\Presenters\CatalogOptions;
use App\Models\Finishing;
use App\Models\Frame;
use App\Models\StandardSize;
use App\Models\User;
use App\Support\CatalogAssets;
use Database\Seeders\MasterDataSeeder;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    CatalogAssets::$enabled = true;
    $this->seed(MasterDataSeeder::class);
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->staff()->create();
    $admin->assignRole(Role::SuperAdmin->value);
    $this->actingAs($admin);
});

afterEach(function () {
    CatalogAssets::$enabled = null;
});

test('every finishing option gets a preview picture, shown in admin and to customers', function () {
    $finishings = Finishing::with('media')->get();

    expect($finishings)->not->toBeEmpty()
        ->and($finishings->every(fn (Finishing $f) => $f->pictureUrl() !== null))->toBeTrue();

    $portal = collect(CatalogOptions::forEditor()['finishings']);
    expect($portal->pluck('picture')->filter())->toHaveCount($finishings->count());

    $this->get(FinishingResource::getUrl('index'))->assertOk()->assertSee('d-rings-wire', false);
});

test('frames with a rendered corner photo show it in the list and the form', function () {
    $frame = Frame::where('code', 'FR-WAL-30')->firstOrFail();
    // A tiny stand-in for the studio render.
    $jpeg = imagecreatetruecolor(8, 8);
    $path = sys_get_temp_dir().'/frame-test.jpg';
    imagejpeg($jpeg, $path);
    CatalogAssets::attach($frame, 'corner', $path, replace: true);

    $this->get(FrameResource::getUrl('index'))->assertOk()->assertSee('frame-test', false);
    $this->get(FrameResource::getUrl('edit', ['record' => $frame]))->assertOk()->assertSee('Corner sample photo');
});

test('standard sizes are entered and listed in centimetres but stored in millimetres', function () {
    Livewire::test(ManageStandardSizes::class)
        ->callAction(CreateAction::class, data: ['width_mm' => '33.3', 'height_mm' => '47.5', 'is_active' => true])
        ->assertHasNoFormErrors();

    $size = StandardSize::where('width_mm', 333)->where('height_mm', 475)->first();
    expect($size)->not->toBeNull();

    Livewire::test(ManageStandardSizes::class)
        ->assertSee('33.3 cm')
        ->assertSee('47.5 cm');
});
