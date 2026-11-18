<?php

use App\Enums\ArtworkPricingModel;
use App\Enums\ArtworkType;
use App\Enums\PrintingMethod;
use App\Enums\Role;
use App\Enums\SubstrateFinish;
use App\Enums\SubstrateMaterial;
use App\Enums\SubstrateUnit;
use App\Filament\Resources\Catalog\Artworks\ArtworkResource;
use App\Filament\Resources\Catalog\Artworks\Pages\ListArtworks;
use App\Filament\Resources\Catalog\Categories\CategoryResource;
use App\Filament\Resources\Catalog\Categories\Pages\ManageCategories;
use App\Filament\Resources\Catalog\Categories\Pages\ManageCategorySubcategories;
use App\Filament\Resources\Catalog\Substrates\Pages\ManageSubstrates;
use App\Http\Presenters\CatalogOptions;
use App\Models\Artwork;
use App\Models\Category;
use App\Models\Substrate;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\MasterDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

function catalogArtwork(string $code, string $categorySlug, string $substrateName): Artwork
{
    $artwork = Artwork::create([
        'code' => $code,
        'title' => "Artwork {$code}",
        'type' => ArtworkType::PrintOpenEdition,
        'native_width_mm' => 600,
        'native_height_mm' => 900,
        'pricing_model' => ArtworkPricingModel::PerSquareMetre,
        'price_per_m2' => 100000,
        'is_active' => true,
    ]);
    $artwork->categories()->sync([Category::where('slug', $categorySlug)->value('id')]);
    $artwork->substrates()->sync([Substrate::where('name', $substrateName)->value('id')]);

    return $artwork;
}

beforeEach(function () {
    $this->seed([MasterDataSeeder::class, CategorySeeder::class]);
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->staff()->create();
    $admin->assignRole(Role::SuperAdmin->value);
    $this->actingAs($admin);
});

test('category seeder builds the category / subcategory tree with materials', function () {
    $canvas = Category::where('slug', 'canvas-paintings')->firstOrFail();

    expect(Category::whereNull('parent_id')->count())->toBe(count(CategorySeeder::TREE))
        ->and($canvas->material)->toBe('Canvas fabric')
        ->and($canvas->children()->pluck('name')->all())->toContain('Abstract', 'Modern')
        ->and(Category::where('slug', 'canvas-abstract')->first()->fullName())->toBe('Canvas Paintings › Abstract');
});

test('substrates carry material, thickness / GSM, finish, printing methods, sizes and unit', function () {
    $paper = Substrate::where('name', 'Fine-art paper 310 gsm')->firstOrFail();

    expect($paper->material_type)->toBe(SubstrateMaterial::Paper)
        ->and($paper->gsm)->toBe(310)
        ->and($paper->finish)->toBe(SubstrateFinish::Matte)
        ->and($paper->printing_methods)->toContain(PrintingMethod::Inkjet->value)
        ->and($paper->available_sizes)->toBe(['12×18', '18×24', '24×36'])
        ->and($paper->unit)->toBe(SubstrateUnit::Sheet)
        ->and(Substrate::where('material_type', 'glass')->exists())->toBeTrue();
});

test('substrate list filters by material, finish and printing method', function () {
    $paper = Substrate::where('name', 'Fine-art paper 310 gsm')->firstOrFail();
    $wood = Substrate::where('name', 'Wood panel 18 mm')->firstOrFail();

    Livewire::test(ManageSubstrates::class)
        ->filterTable('material_type', 'paper')
        ->assertCanSeeTableRecords([$paper])
        ->assertCanNotSeeTableRecords([$wood]);

    Livewire::test(ManageSubstrates::class)
        ->filterTable('printing_method', 'inkjet')
        ->assertCanSeeTableRecords([$paper])
        ->assertCanNotSeeTableRecords([$wood]);

    Livewire::test(ManageSubstrates::class)
        ->filterTable('finish', 'textured')
        ->assertCanSeeTableRecords([$wood])
        ->assertCanNotSeeTableRecords([$paper]);
});

test('artwork list filters by category (incl. subcategories), subcategory and substrate specs', function () {
    $canvasArt = catalogArtwork('ART-T1', 'canvas-abstract', 'Stretched canvas 38 mm');
    $modernArt = catalogArtwork('ART-T2', 'vastu-waterfall', 'Glass print 4 mm');
    $paperArt = catalogArtwork('ART-T3', 'hand-painting-modern', 'Fine-art paper 310 gsm');

    Livewire::test(ListArtworks::class)
        ->filterTable('category', Category::where('slug', 'canvas-paintings')->value('id'))
        ->assertCanSeeTableRecords([$canvasArt])
        ->assertCanNotSeeTableRecords([$modernArt, $paperArt]);

    Livewire::test(ListArtworks::class)
        ->filterTable('subcategory', [Category::where('slug', 'vastu-waterfall')->value('id')])
        ->assertCanSeeTableRecords([$modernArt])
        ->assertCanNotSeeTableRecords([$canvasArt, $paperArt]);

    Livewire::test(ListArtworks::class)
        ->filterTable('material_type', 'glass')
        ->assertCanSeeTableRecords([$modernArt])
        ->assertCanNotSeeTableRecords([$canvasArt, $paperArt]);

    Livewire::test(ListArtworks::class)
        ->filterTable('printing_method', 'inkjet')
        ->assertCanSeeTableRecords([$canvasArt, $paperArt])
        ->assertCanNotSeeTableRecords([$modernArt]);

    Livewire::test(ListArtworks::class)
        ->filterTable('finish', 'glossy')
        ->assertCanSeeTableRecords([$modernArt])
        ->assertCanNotSeeTableRecords([$canvasArt, $paperArt]);
});

test('portal catalog lists categories with their subcategories', function () {
    catalogArtwork('ART-T1', 'canvas-abstract', 'Stretched canvas 38 mm');
    $options = CatalogOptions::forEditor();

    expect($options['categories'])->toContain('Canvas Paintings', 'Canvas Paintings › Abstract')
        ->and($options['artworks'][0]['categories'])->toBe(['Canvas Paintings', 'Canvas Paintings › Abstract']);
});

test('each category links to its own subcategory list, where new subcategories belong to it', function () {
    $canvas = Category::where('slug', 'canvas-paintings')->firstOrFail();
    $vastu = Category::where('slug', 'vastu-divinity')->firstOrFail();
    $abstract = Category::where('slug', 'canvas-abstract')->firstOrFail();
    $peacock = Category::where('slug', 'vastu-peacock')->firstOrFail();

    // Category list: top level only, with a link to each category's subcategories.
    Livewire::test(ManageCategories::class)
        ->assertCanSeeTableRecords([$canvas, $vastu])
        ->assertCanNotSeeTableRecords([$abstract])
        ->assertSee(CategoryResource::getUrl('subcategories', ['record' => $canvas]), false)
        ->assertSee('13 subcategories');

    // Subcategories page of "Canvas Paintings": only its own subcategories.
    $this->get(CategoryResource::getUrl('subcategories', ['record' => $canvas]))
        ->assertOk()
        ->assertSee('Canvas Paintings')
        ->assertSee('Abstract')
        ->assertDontSee('Peacock');

    Livewire::test(ManageCategorySubcategories::class, ['record' => $canvas->getKey()])
        ->assertCanSeeTableRecords([$abstract])
        ->assertCanNotSeeTableRecords([$peacock, $vastu])
        ->callAction(TestAction::make('create')->table(), data: ['name' => 'Minimal', 'slug' => 'canvas-minimal', 'is_active' => true])
        ->assertHasNoFormErrors();

    expect(Category::where('slug', 'canvas-minimal')->first()->parent_id)->toBe($canvas->id);

    // No separate "Subcategories" item in the sidebar any more.
    expect(collect(Filament::getCurrentPanel()->getNavigation())->flatMap(fn ($group) => $group->getItems())->map(fn ($item) => $item->getLabel()))
        ->not->toContain('Subcategories');
});

test('artworks list shows picture cards by default and a compact list on request', function () {
    $art = catalogArtwork('ART-T1', 'canvas-abstract', 'Stretched canvas 38 mm');

    Livewire::test(ListArtworks::class)
        ->assertSet('display', 'grid')
        ->assertCanSeeTableRecords([$art])
        ->assertSee('Canvas Paintings: Abstract')
        ->assertSee('₹1,000 / m²')
        ->assertSee('Print — open edition');

    Livewire::withQueryParams(['view' => 'list'])
        ->test(ListArtworks::class)
        ->assertSet('display', 'list')
        ->assertCanSeeTableRecords([$art])
        ->assertSee('Canvas Paintings › Abstract')
        ->assertSee('₹1,000.00 / m²');

    $this->get(ArtworkResource::getUrl('index'))->assertOk()->assertSee('List view');
    $this->get(ArtworkResource::getUrl('index', ['view' => 'list']))->assertOk()->assertSee('Grid view');
});
