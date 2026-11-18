<?php

namespace Database\Seeders;

use App\Enums\ArtworkPricingModel;
use App\Enums\ArtworkType;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\Category;
use App\Models\Medium;
use App\Models\StandardSize;
use Database\Seeders\Support\DemoImage;
use Illuminate\Database\Seeder;

/**
 * Demo artists, categories and artworks (with generated images) for local development only.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // Artworks go into subcategories (created by CategorySeeder).
        $this->call(CategorySeeder::class);
        $categories = [
            'Abstract' => Category::where('slug', 'canvas-abstract')->firstOrFail(),
            'Landscape' => Category::where('slug', 'canvas-scenery')->firstOrFail(),
            'Botanical' => Category::where('slug', 'canvas-floral')->firstOrFail(),
            'Photography' => Category::where('slug', 'canvas-modern')->firstOrFail(),
            'Indian contemporary' => Category::where('slug', 'canvas-ethnic')->firstOrFail(),
            'Watercolour' => Category::where('slug', 'hand-painting-modern')->firstOrFail(),
        ];

        $asha = Artist::firstOrCreate(['name' => 'Asha Menon'], ['bio' => 'Abstract painter working in layered acrylics.', 'country' => 'IN']);
        $luca = Artist::firstOrCreate(['name' => 'Luca Bianchi'], ['bio' => 'Landscape photographer.', 'country' => 'IT']);

        $giclee = Medium::where('name', 'Giclée print')->first();
        $acrylic = Medium::where('name', 'Acrylic on canvas')->first();

        $monsoon = Artwork::firstOrCreate(['code' => 'ART-0001'], [
            'title' => 'Monsoon Blue',
            'artist_id' => $asha->id,
            'description' => 'Layered blues and greys inspired by the first rains.',
            'type' => ArtworkType::PrintOpenEdition,
            'style_tags' => ['blue', 'calm', 'large'],
            'default_medium_id' => $giclee?->id,
            'native_width_mm' => 1000,
            'native_height_mm' => 1400,
            'min_width_mm' => 300,
            'max_width_mm' => 1500,
            'allow_custom_size' => true,
            'pricing_model' => ArtworkPricingModel::FixedPerSize,
            'lead_time_days' => 10,
            'is_featured' => true,
        ]);
        if ($monsoon->wasRecentlyCreated) {
            $monsoon->categories()->sync([$categories['Abstract']->id, $categories['Indian contemporary']->id]);
            $prices = [[500, 700, 650000], [700, 1000, 1050000], [1000, 1500, 1800000]];
            foreach ($prices as $i => [$w, $h, $price]) {
                $monsoon->sizePrices()->create([
                    'standard_size_id' => StandardSize::where(['width_mm' => $w, 'height_mm' => $h])->value('id'),
                    'width_mm' => $w, 'height_mm' => $h, 'price' => $price, 'sort_order' => $i,
                ]);
            }
        }

        $dolomites = Artwork::firstOrCreate(['code' => 'ART-0002'], [
            'title' => 'Dolomites at Dawn',
            'artist_id' => $luca->id,
            'type' => ArtworkType::PrintLimitedEdition,
            'edition_size' => 50,
            'default_medium_id' => $giclee?->id,
            'native_width_mm' => 1500,
            'native_height_mm' => 1000,
            'allow_custom_size' => true,
            'pricing_model' => ArtworkPricingModel::PerSquareMetre,
            'price_per_m2' => 1200000,
            'lead_time_days' => 7,
        ]);
        if ($dolomites->wasRecentlyCreated) {
            $dolomites->categories()->sync([$categories['Landscape']->id, $categories['Photography']->id]);
        }

        $original = Artwork::firstOrCreate(['code' => 'ART-0003'], [
            'title' => 'Lotus Study I',
            'artist_id' => $asha->id,
            'type' => ArtworkType::Original,
            'default_medium_id' => $acrylic?->id,
            'native_width_mm' => 600,
            'native_height_mm' => 600,
            'allow_custom_size' => false,
            'pricing_model' => ArtworkPricingModel::FixedPerSize,
            'lead_time_days' => 3,
        ]);
        if ($original->wasRecentlyCreated) {
            $original->categories()->sync([$categories['Watercolour']->id]);
            $original->sizePrices()->create(['width_mm' => 600, 'height_mm' => 600, 'price' => 4500000, 'sort_order' => 0]);
        }

        $more = [
            ['ART-0004', 'Saffron Fields', $luca, 'Landscape', 1200, 800, 'horizon', ['#F2C14E', '#F78154', '#B4436C', '#5D2E46']],
            ['ART-0005', 'Kerala Greens', $asha, 'Botanical', 700, 1000, 'botanical', ['#E9F5DB', '#CFE1B9', '#87986A', '#4F6D3A', '#2D4A22']],
            ['ART-0006', 'Terracotta Rhythm', $asha, 'Abstract', 900, 900, 'blobs', ['#F4E1D2', '#E07A5F', '#81B29A', '#3D405B', '#F2CC8F']],
        ];
        foreach ($more as [$code, $title, $artist, $category, $w, $h, $style, $palette]) {
            $artwork = Artwork::firstOrCreate(['code' => $code], [
                'title' => $title,
                'artist_id' => $artist->id,
                'type' => ArtworkType::PrintOpenEdition,
                'default_medium_id' => $giclee?->id,
                'native_width_mm' => $w,
                'native_height_mm' => $h,
                'allow_custom_size' => true,
                'pricing_model' => ArtworkPricingModel::PerSquareMetre,
                'price_per_m2' => 950000,
                'lead_time_days' => 7,
                'is_featured' => $code === 'ART-0006',
            ]);
            if ($artwork->wasRecentlyCreated) {
                $artwork->categories()->sync([$categories[$category]->id]);
            }
        }

        // Generated images for every demo artwork that has none yet.
        $looks = [
            'ART-0001' => ['blobs', ['#DCE6F0', '#5B7DB1', '#2E4A7D', '#A7C4E2', '#8FA3B8']],
            'ART-0002' => ['horizon', ['#FDE2C8', '#F6B78C', '#7B8FA6', '#3E4E63', '#24303F']],
            'ART-0003' => ['botanical', ['#FBF3E8', '#F5C2C7', '#E58F9E', '#7FB685', '#3C6E47']],
            'ART-0004' => ['horizon', ['#F2C14E', '#F78154', '#B4436C', '#5D2E46']],
            'ART-0005' => ['botanical', ['#E9F5DB', '#CFE1B9', '#87986A', '#4F6D3A', '#2D4A22']],
            'ART-0006' => ['blobs', ['#F4E1D2', '#E07A5F', '#81B29A', '#3D405B', '#F2CC8F']],
        ];
        foreach (Artwork::whereIn('code', array_keys($looks))->get() as $i => $artwork) {
            if ($artwork->hasMedia('main')) {
                continue;
            }
            [$style, $palette] = $looks[$artwork->code];
            $ratio = ($artwork->native_height_mm ?: 1000) / ($artwork->native_width_mm ?: 1000);
            $path = DemoImage::abstract(1200, (int) round(1200 * $ratio), $palette, crc32($artwork->code), $style);
            $artwork->addMedia($path)->usingFileName(str($artwork->title)->slug().'.jpg')->toMediaCollection('main');
        }
    }
}
