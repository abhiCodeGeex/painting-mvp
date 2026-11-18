<?php

use App\Enums\FrameProfile;
use App\Filament\Resources\Framing\Frames\Pages\EditFrame;
use App\Enums\Role;
use App\Http\Presenters\CatalogOptions;
use App\Models\Frame;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(MasterDataSeeder::class);
});

test('frames carry a moulding profile for the 3D preview', function () {
    $frames = collect(CatalogOptions::forEditor()['frames'])->keyBy('code');

    expect($frames['FR-GLD-40']['profile'])->toBe('ornate')
        ->and($frames['FR-FLT-BLK']['profile'])->toBe('floater')
        ->and($frames['FR-WAL-30']['profile'])->toBe('scoop')
        ->and($frames['FR-BLK-20']['profile'])->toBe('flat');
});

test('admin can change a frame profile', function () {
    $admin = User::factory()->staff()->create();
    $admin->assignRole(Role::SuperAdmin->value);
    $frame = Frame::where('code', 'FR-BLK-20')->firstOrFail();

    Livewire::actingAs($admin)
        ->test(EditFrame::class, ['record' => $frame->getKey()])
        ->fillForm(['profile' => FrameProfile::Stepped->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($frame->fresh()->profile)->toBe(FrameProfile::Stepped);
});
