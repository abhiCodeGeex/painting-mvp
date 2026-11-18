<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Artwork categories and their subcategories, following the "Canvas Paintings",
 * "Vastu & Divinity" and "Hand Painting" menus of cipherspaces.com.
 */
class CategorySeeder extends Seeder
{
    /**
     * slug => [name, material, description, [child slug => child name]].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: array<string, string>}>
     */
    public const TREE = [
        'canvas-paintings' => ['Canvas Paintings', 'Canvas fabric', 'Versatile artwork printed on canvas.', [
            'canvas-modern' => 'Modern',
            'canvas-abstract' => 'Abstract',
            'canvas-pichwai' => 'Pichwai',
            'canvas-motivational' => 'Motivational',
            'canvas-car' => 'Car Paintings',
            'canvas-movies-tv-anime' => 'Movies, TV & Anime',
            'canvas-scenery' => 'Scenery',
            'canvas-ethnic' => 'Ethnic',
            'canvas-boho' => 'Boho',
            'canvas-vintage-baroque' => 'Vintage / Baroque',
            'canvas-african' => 'African',
            'canvas-animal' => 'Animal',
            'canvas-floral' => 'Floral',
        ]],
        'vastu-divinity' => ['Vastu & Divinity', 'Canvas fabric', 'Paintings chosen for wall direction, positive energy and devotion.', [
            'vastu-wall-direction' => 'Wall Direction',
            'vastu-radha-krishna' => 'Radha Krishna Ji',
            'vastu-shrinath-ji' => 'Shrinath Ji',
            'vastu-peacock' => 'Peacock',
            'vastu-kamadhenu' => 'Kamadhenu',
            'vastu-waterfall' => 'Waterfall',
            'vastu-running-horses' => 'Running Horses',
            'vastu-hindu-goddesses' => 'Hindu Goddesses',
            'vastu-hanuman-ji' => 'Hanuman Ji',
            'vastu-phoenix' => 'Phoenix',
            'vastu-tirupati-balaji' => 'Tirupati Balaji',
            'vastu-jagannath-ji' => 'Jagannath Ji',
            'vastu-lord-shiva' => 'Lord Shiva',
            'vastu-shree-ram-ji' => 'Shree Ram Ji',
            'vastu-neem-karoli-baba' => 'Neem Karoli Baba Ji',
            'vastu-premanand-ji' => 'Premanand Ji',
            'vastu-koi-fish' => 'Koi Fish',
            'vastu-buddha' => 'Buddha',
        ]],
        'hand-painting' => ['Hand Painting', 'Manual painting', 'Original artistic touch, painted by hand.', [
            'hand-painting-spiritual' => 'Spiritual',
            'hand-painting-modern' => 'Modern',
        ]],
    ];

    public function run(): void
    {
        $i = 0;
        foreach (self::TREE as $slug => [$name, $material, $description, $children]) {
            $parent = Category::updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'parent_id' => null, 'material' => $material, 'description' => $description, 'sort_order' => $i++, 'is_active' => true],
            );
            $j = 0;
            foreach ($children as $childSlug => $childName) {
                Category::updateOrCreate(
                    ['slug' => $childSlug],
                    ['name' => $childName, 'parent_id' => $parent->id, 'material' => null, 'sort_order' => $j++, 'is_active' => true],
                );
            }
        }
    }

    /**
     * Every slug the seeder owns (categories and subcategories).
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return collect(self::TREE)->flatMap(fn (array $node, string $slug) => [$slug, ...array_keys($node[3])])->values()->all();
    }
}
