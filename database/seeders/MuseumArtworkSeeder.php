<?php

namespace Database\Seeders;

use App\Enums\ArtworkPricingModel;
use App\Enums\ArtworkType;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\Category;
use App\Models\Medium;
use App\Support\CatalogAssets;
use Illuminate\Database\Seeder;

/**
 * Public-domain paintings from museum open-access collections (Art Institute of Chicago and
 * The Met, both CC0), about two per subcategory, so every category shows real artworks.
 * Images live in database/seeders/assets/artworks/{subcategory}/{slug}.jpg; credits and
 * licences are in database/seeders/assets/artworks/CREDITS.md.
 */
class MuseumArtworkSeeder extends Seeder
{
    /**
     * code, subcategory slug, file slug, title, artist, artist country, date, medium, museum credit,
     * image width px, image height px, price per m² (paise).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string, 8: string, 9: int, 10: int, 11: int}>
     */
    public const ARTWORKS = [
        ['MUS-AIC-28560', 'canvas-modern', 'the-bedroom', 'The Bedroom', 'Vincent van Gogh', 'NL', '1889', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 3000, 2348, 950000],
        ['MUS-AIC-27984', 'canvas-modern', 'woman-before-an-aquarium', 'Woman before an Aquarium', 'Henri Matisse', 'FR', '1921–23', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 3000, 2395, 950000],
        ['MUS-AIC-8991', 'canvas-abstract', 'improvisation-no-30-cannons', 'Improvisation No. 30 (Cannons)', 'Vasily Kandinsky', 'RU', '1913', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 2987, 3000, 950000],
        ['MUS-AIC-8987', 'canvas-abstract', 'painting-with-green-center', 'Painting with Green Center', 'Vasily Kandinsky', 'RU', '1913', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 2417, 2250, 950000],
        ['MUS-MET-456958', 'canvas-pichwai', 'pichwai-hanging', 'Pichwai Hanging', 'Indian School', 'IN', 'late 18th century', 'Cotton, pigments, gold leaf; plain weave, dyed, and painted', 'The Metropolitan Museum of Art (CC0)', 2200, 1700, 950000],
        ['MUS-MET-876115', 'canvas-pichwai', 'temple-cloth-celebrating-krishna', 'Temple Cloth Celebrating Krishna (Pichhavai)', 'Deccan School', 'IN', '19th century', 'Painted pigments (kalamkari) and glued gold on cotton', 'The Metropolitan Museum of Art (CC0)', 2162, 3000, 950000],
        ['MUS-MET-11235', 'canvas-motivational', 'sunrise-inness', 'Sunrise', 'George Inness', 'US', '1887', 'Oil on canvas', 'The Metropolitan Museum of Art (CC0)', 3000, 1993, 950000],
        ['MUS-AIC-94841', 'canvas-motivational', 'the-song-of-the-lark', 'The Song of the Lark', 'Jules Breton', 'FR', '1884', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 2313, 3000, 950000],
        ['MUS-AIC-100350', 'canvas-car', 'chariot-race', 'Chariot Race', 'Jean Léon Gérôme', 'FR', '1876', 'Oil on cradled panel', 'Art Institute of Chicago (CC0)', 3000, 1659, 950000],
        ['MUS-AIC-137884', 'canvas-car', 'tyburn-the-motor-car', 'Tyburn, The Motor Car', 'Joseph Pennell', 'US', '1906', 'Etching on buff laid paper', 'Art Institute of Chicago (CC0)', 3000, 2099, 950000],
        ['MUS-AIC-24645', 'canvas-movies-tv-anime', 'the-great-wave', 'The Great Wave off Kanagawa', 'Katsushika Hokusai', 'JP', '1830/33', 'Color woodblock print; oban', 'Art Institute of Chicago (CC0)', 3000, 2056, 950000],
        ['MUS-AIC-49141', 'canvas-movies-tv-anime', 'yoshitsune-defeats-benkei', 'The Young Yoshitsune defeats Benkei at Gojo Bridge', 'Utagawa Kuniyoshi', 'JP', 'c. 1848', 'Color woodblock prints; oban triptych', 'Art Institute of Chicago (CC0)', 3000, 1475, 950000],
        ['MUS-AIC-4783', 'canvas-scenery', 'poppy-field-giverny', 'Poppy Field (Giverny)', 'Claude Monet', 'FR', '1890–91', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 3000, 1948, 950000],
        ['MUS-AIC-76571', 'canvas-scenery', 'view-of-cotopaxi', 'View of Cotopaxi', 'Frederic Edwin Church', 'US', '1857', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 3000, 1992, 950000],
        ['MUS-AIC-76899', 'canvas-ethnic', 'asavari-ragini', 'Asavari Ragini (Ragamala)', 'Indian School', 'IN', 'Mid–to late 17th century', 'Opaque watercolor on paper', 'Art Institute of Chicago (CC0)', 2102, 3000, 950000],
        ['MUS-MET-38040', 'canvas-ethnic', 'maharana-jagat-singh-hawks-for-cranes', 'Maharana Jagat Singh Hawks for Cranes', 'Shiva and Dayal', 'IN', 'dated 1744', 'Opaque watercolor, ink and gold on paper', 'The Metropolitan Museum of Art (CC0)', 3000, 2788, 950000],
        ['MUS-AIC-27943', 'canvas-boho', 'mahana-no-atua', 'Mahana no atua (Day of the God)', 'Paul Gauguin', 'FR', '1894', 'Oil on linen canvas', 'Art Institute of Chicago (CC0)', 3000, 2238, 950000],
        ['MUS-AIC-111986', 'canvas-boho', 'zodiaque-la-plume', 'Zodiaque ("La Plume")', 'Alphonse Marie Mucha', 'CZ', '1896–97', 'Color lithograph from multiple stones on tan wove paper', 'Art Institute of Chicago (CC0)', 2235, 3000, 950000],
        ['MUS-AIC-27310', 'canvas-vintage-baroque', 'holy-family-with-saints', 'The Holy Family with Saints Elizabeth and John the Baptist', 'Peter Paul Rubens', 'BE', 'c. 1615', 'Oil on panel', 'Art Institute of Chicago (CC0)', 2391, 3000, 950000],
        ['MUS-AIC-95998', 'canvas-vintage-baroque', 'old-man-with-a-gold-chain', 'Old Man with a Gold Chain', 'Rembrandt van Rijn', 'NL', '1631', 'Oil on panel', 'Art Institute of Chicago (CC0)', 2754, 3000, 950000],
        ['MUS-MET-844779', 'canvas-african', 'ethiopian-virgin-and-child-wall-painting', 'Virgin and Child with Donors', 'Ethiopian Northern Highlands School', 'ET', 'ca. 1800', 'Pigment over gesso on canvas', 'The Metropolitan Museum of Art (CC0)', 2409, 3000, 950000],
        ['MUS-MET-319625', 'canvas-african', 'ewostatewos-and-his-disciples', 'Icon Triptych of Ewostatewos and His Disciples', 'Ethiopian Northern Highlands School', 'ET', 'late 17th century', 'Wood, tempera, cord', 'The Metropolitan Museum of Art (CC0)', 2291, 3000, 950000],
        ['MUS-AIC-81505', 'canvas-animal', 'lion-hunt', 'Lion Hunt', 'Eugène Delacroix', 'FR', '1860–61', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 3000, 2334, 950000],
        ['MUS-MET-49061', 'canvas-animal', 'tiger-gan-ku', 'Tiger', 'Gan Ku', 'JP', 'dated 1790', 'Hanging scroll; ink and color on silk', 'The Metropolitan Museum of Art (CC0)', 1204, 2865, 950000],
        ['MUS-AIC-14561', 'canvas-floral', 'the-vase-of-tulips', 'The Vase of Tulips', 'Paul Cezanne', 'FR', 'c. 1890', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 2116, 3000, 950000],
        ['MUS-AIC-16568', 'canvas-floral', 'water-lilies', 'Water Lilies', 'Claude Monet', 'FR', '1906', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 3000, 2881, 950000],
        ['MUS-AIC-21161', 'vastu-wall-direction', 'mount-fuji-at-sunrise', 'Mount Fuji at Sunrise on New Year\'s Day', 'Isoda Koryusai', 'JP', 'c. 1772', 'Color woodblock print; chuban', 'Art Institute of Chicago (CC0)', 2294, 3000, 1050000],
        ['MUS-MET-10158', 'vastu-wall-direction', 'sunrise-on-the-matterhorn', 'Sunrise on the Matterhorn', 'Albert Bierstadt', 'US', 'after 1875', 'Oil on canvas', 'The Metropolitan Museum of Art (CC0)', 2193, 3000, 1050000],
        ['MUS-AIC-93926', 'vastu-radha-krishna', 'krishna-and-radha-in-a-pavilion', 'Krishna and Radha in a Pavilion', 'Indian School', 'IN', '1719', 'Opaque watercolor and gold on paper', 'Art Institute of Chicago (CC0)', 2580, 3000, 1050000],
        ['MUS-MET-65594', 'vastu-radha-krishna', 'radha-and-krishna-walk-in-a-flowering-grove', 'Radha and Krishna Walk in a Flowering Grove', 'Kota Master', 'IN', 'ca. 1720', 'Ink, opaque watercolor, and gold on paper', 'The Metropolitan Museum of Art (CC0)', 2100, 3000, 1050000],
        ['MUS-MET-72719', 'vastu-shrinath-ji', 'krishna-in-the-form-of-shri-nathji', 'Krishna in the Form of Shri Nathji', 'Nathdwara School', 'IN', 'ca. 1840', 'Opaque watercolor and gold on paper', 'The Metropolitan Museum of Art (CC0)', 2178, 3000, 1050000],
        ['MUS-MET-861028', 'vastu-shrinath-ji', 'the-gopis-celebrating-krishna', 'The Gopis celebrating Krishna', 'Deccan School', 'IN', '1800–25', 'Border panel from a picchavai hanging; cotton with painted and stamped mordant and resist dyed, and gold', 'The Metropolitan Museum of Art (CC0)', 3000, 1081, 1050000],
        ['MUS-AIC-21504', 'vastu-peacock', 'peacock-and-peonies', 'Peacock and Peonies', 'Utagawa Hiroshige', 'JP', 'early 1840s', 'Color woodblock print; vertical oban diptych', 'Art Institute of Chicago (CC0)', 996, 2250, 1050000],
        ['MUS-MET-49003', 'vastu-peacock', 'peacocks-and-peonies-buncho', 'Peacocks and Peonies', 'Tani Bunchō', 'JP', '1820', 'Hanging scroll; ink and color on silk', 'The Metropolitan Museum of Art (CC0)', 1246, 2000, 1050000],
        ['MUS-MET-37847', 'vastu-kamadhenu', 'krishna-and-the-cowherds', 'Krishna and the Cowherds', 'Mughal-era Delhi-Agra School', 'IN', 'ca. 1520–30', 'Ink and opaque watercolor on paper', 'The Metropolitan Museum of Art (CC0)', 2400, 2168, 1050000],
        ['MUS-MET-65576', 'vastu-kamadhenu', 'pichhwai-for-the-festival-of-cows', 'Pichhwai for the Festival of Cows', 'Deccan School', 'IN', 'late 18th century', 'Painted and printed gold and silver leaf and opaque watercolor on indigo-dyed cotton', 'The Metropolitan Museum of Art (CC0)', 3000, 2889, 1050000],
        ['MUS-MET-12845', 'vastu-waterfall', 'waterfall-twachtman', 'Waterfall', 'John Henry Twachtman', 'US', 'ca. 1889–91', 'Oil on canvas', 'The Metropolitan Museum of Art (CC0)', 2482, 3000, 1050000],
        ['MUS-AIC-13255', 'vastu-waterfall', 'yoro-waterfall', 'Yoro Waterfall in Mino Province', 'Katsushika Hokusai', 'JP', 'c. 1833', 'Color woodblock print; oban', 'Art Institute of Chicago (CC0)', 2044, 3000, 1050000],
        ['MUS-MET-435702', 'vastu-running-horses', 'the-horse-fair', 'The Horse Fair', 'Rosa Bonheur', 'FR', '1852–55', 'Oil on canvas', 'The Metropolitan Museum of Art (CC0)', 3000, 1532, 1050000],
        ['MUS-AIC-81533', 'vastu-running-horses', 'the-races-at-longchamp', 'The Races at Longchamp', 'Édouard Manet', 'FR', '1866', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 3000, 1561, 1050000],
        ['MUS-MET-78264', 'vastu-hindu-goddesses', 'lakshmi', 'Lakshmi', 'Maharashtra School', 'IN', '1894', 'Chromolithograph on paper', 'The Metropolitan Museum of Art (CC0)', 2250, 3000, 1050000],
        ['MUS-MET-786763', 'vastu-hindu-goddesses', 'shri-shri-durga', 'Shri Shri Durga', 'Calcutta (Kolkata) School', 'IN', 'ca. 1885–95', 'Chromolithographic print on paper', 'The Metropolitan Museum of Art (CC0)', 2252, 3000, 1050000],
        ['MUS-MET-37960', 'vastu-hanuman-ji', 'hanuman-bearing-the-mountaintop', 'Hanuman Bearing the Mountaintop with Medicinal Herbs', 'Rajasthani School', 'IN', 'ca. 1800', 'Ink and opaque watercolor on cloth', 'The Metropolitan Museum of Art (CC0)', 2563, 2841, 1050000],
        ['MUS-MET-826439', 'vastu-hanuman-ji', 'panchamukha-hanuman', 'Panchamukha Hanuman', 'Rajasthani School', 'IN', 'ca. late 19th century', 'Ink and opaque watercolor on paper', 'The Metropolitan Museum of Art (CC0)', 2548, 3000, 1050000],
        ['MUS-MET-36045', 'vastu-phoenix', 'blue-phoenix', 'Blue Phoenix', 'Jiang Baohua', 'CN', 'late 19th–early 20th century', 'Hanging scroll; ink and color on paper', 'The Metropolitan Museum of Art (CC0)', 1035, 3000, 1050000],
        ['MUS-AIC-44206', 'vastu-phoenix', 'young-woman-riding-a-phoenix', 'Young Woman Riding a Phoenix', 'Suzuki Harunobu', 'JP', '1765', 'Color woodblock print; chuban yoko-e', 'Art Institute of Chicago (CC0)', 3000, 2248, 1050000],
        ['MUS-MET-858237', 'vastu-tirupati-balaji', 'krishna-rajagopalaswamy', 'Krishna Rajagopalaswamy, King of the Cowherds', 'Tirupati School, South India', 'IN', 'late 18th century', 'Opaque watercolor and hand-colored silver on paper', 'The Metropolitan Museum of Art (CC0)', 2143, 3000, 1050000],
        ['MUS-MET-858236', 'vastu-tirupati-balaji', 'vishnu-venkateshvara-lord-of-tirupati', 'Vishnu Venkateshvara, Lord of Tirupati', 'Tirupati School, South India', 'IN', 'late 18th century', 'Opaque watercolor and hand-colored silver on paper', 'The Metropolitan Museum of Art (CC0)', 2131, 3000, 1050000],
        ['MUS-MET-786762', 'vastu-jagannath-ji', 'shri-shri-jagannatha', 'Shri Shri Jagannatha, Lord of the World', 'Calcutta (Kolkata) School', 'IN', 'ca. 1890', 'Chromolithographic on paper', 'The Metropolitan Museum of Art (CC0)', 2252, 3000, 1050000],
        ['MUS-MET-823585', 'vastu-jagannath-ji', 'worship-of-lord-jagannatha-at-puri', 'Worship of Lord Jagannatha in His Temple at Puri', 'Nepalese School', 'NP', 'early 18th century', 'Opaque pigments on cotton', 'The Metropolitan Museum of Art (CC0)', 2250, 3000, 1050000],
        ['MUS-MET-74668', 'vastu-lord-shiva', 'shiva-and-parvati', 'Shiva and Parvati', 'Pahari School', 'IN', '19th century', 'Ink and translucent watercolor on paper', 'The Metropolitan Museum of Art (CC0)', 1407, 1572, 1050000],
        ['MUS-MET-37942', 'vastu-lord-shiva', 'shiva-and-parvati-playing-chaupar', 'Shiva and Parvati Playing Chaupar', 'Devidasa of Nurpur', 'IN', 'dated 1694–95', 'Opaque watercolor, ink, silver, and gold on paper', 'The Metropolitan Museum of Art (CC0)', 2000, 1305, 1050000],
        ['MUS-MET-37955', 'vastu-shree-ram-ji', 'rama-sita-and-lakshmana-at-the-hermitage', 'Rama, Sita and Lakshmana at the Hermitage of Bharadvaja', 'Pahari School', 'IN', 'ca. 1780', 'Opaque watercolor and ink on paper', 'The Metropolitan Museum of Art (CC0)', 3000, 2131, 1050000],
        ['MUS-MET-688475', 'vastu-shree-ram-ji', 'the-assembly-of-rama', 'The Assembly of Rama', 'Maharashtra School', 'IN', 'ca. 1910', 'Chromolithographic on paper', 'The Metropolitan Museum of Art (CC0)', 2250, 3000, 1050000],
        ['MUS-MET-38035', 'vastu-neem-karoli-baba', 'a-holy-man-seated-on-a-terrace', 'A Holy Man Seated on a Terrace', 'Pahari School', 'IN', 'ca. 1850', 'Ink and opaque watercolor on paper', 'The Metropolitan Museum of Art (CC0)', 2099, 3000, 1050000],
        ['MUS-MET-38022', 'vastu-neem-karoli-baba', 'krishna-with-an-ascetic', 'Krishna with an Ascetic', 'Maharashtra School', 'IN', 'ca.1850', 'Ink and opaque watercolor on paper', 'The Metropolitan Museum of Art (CC0)', 3000, 1769, 1050000],
        ['MUS-MET-65595', 'vastu-premanand-ji', 'radha-crosses-a-river-to-meet-a-sage', 'Radha Crosses a River to Interview a Hindu Sage', 'Chokha', 'IN', 'ca. 1820', 'Ink, opaque watercolor, silver, and gold on paper', 'The Metropolitan Museum of Art (CC0)', 3000, 2050, 1050000],
        ['MUS-MET-38009', 'vastu-premanand-ji', 'raja-balwant-singhs-vision-of-krishna-and-radha', 'Raja Balwant Singh\'s Vision of Krishna and Radha', 'Nainsukh', 'IN', 'ca. 1745–50', 'Ink, opaque watercolor, and gold on paper', 'The Metropolitan Museum of Art (CC0)', 2400, 3000, 1050000],
        ['MUS-MET-54740', 'vastu-koi-fish', 'carp-swimming', 'Carp swimming', 'Watanabe Seitei', 'JP', 'ca. 1887', 'Album leaf; ink and color on silk', 'The Metropolitan Museum of Art (CC0)', 2271, 3000, 1050000],
        ['MUS-AIC-24886', 'vastu-koi-fish', 'the-red-carp', 'The Red Carp', 'Utagawa Toyokuni I', 'JP', 'c. 1804/18', 'Color woodblock print; oban', 'Art Institute of Chicago (CC0)', 1912, 3000, 1050000],
        ['MUS-MET-40448', 'vastu-buddha', 'buddha-amitabha-descending', 'Buddha Amitabha Descending from His Pure Land', 'Chinese School', 'CN', 'late 13th century', 'Hanging scroll; ink, color, and gold on silk', 'The Metropolitan Museum of Art (CC0)', 1551, 3000, 1050000],
        ['MUS-MET-75274', 'vastu-buddha', 'buddha-shakyamuni-lord-of-the-munis', 'Buddha Shakyamuni, Lord of the Munis', 'Tibetan School', 'CN', 'mid-17th century', 'Distemper on cloth', 'The Metropolitan Museum of Art (CC0)', 2594, 3000, 1050000],
        ['MUS-AIC-49262', 'hand-painting-spiritual', 'amitayus-buddha-surrounded-by-one-hundred-buddhas', 'Amitayus Buddha Surrounded by One Hundred Buddhas', 'Tibetan School', 'CN', '18th-19th century', 'Opaque watercolor and gold (as a watercolor) on cotton', 'Art Institute of Chicago (CC0)', 1929, 3000, 1450000],
        ['MUS-MET-682119', 'hand-painting-spiritual', 'vasudhara-mandala', 'Vasudhara Mandala', 'Nepalese School', 'NP', 'Dated to 1777 (Samvat 897)', 'Distemper on cloth', 'The Metropolitan Museum of Art (CC0)', 2544, 3000, 1450000],
        ['MUS-AIC-16487', 'hand-painting-modern', 'the-bay-of-marseille', 'The Bay of Marseille, Seen from L\'Estaque', 'Paul Cezanne', 'FR', 'c. 1885', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 3000, 2404, 1450000],
        ['MUS-AIC-14586', 'hand-painting-modern', 'the-poets-garden', 'The Poet\'s Garden', 'Vincent van Gogh', 'NL', '1888', 'Oil on canvas', 'Art Institute of Chicago (CC0)', 3000, 2365, 1450000],
    ];

    public function run(): void
    {
        $this->call(CategorySeeder::class);

        $categories = Category::whereIn('slug', CategorySeeder::slugs())->get()->keyBy('slug');
        $giclee = Medium::where('name', 'Giclée print')->first();

        foreach (self::ARTWORKS as $i => [$code, $sub, $slug, $title, $artistName, $country, $date, $medium, $credit, $px, $py, $perM2]) {
            $category = $categories[$sub] ?? null;
            if (! $category) {
                continue;
            }

            $artist = Artist::firstOrCreate(['name' => $artistName], [
                'bio' => 'Historic artist; works shown here are in the public domain.',
                'country' => $country,
            ]);

            // Native size: the long side is 1 m, the other follows the picture's proportions.
            $ratio = $py / max(1, $px);
            [$w, $h] = $ratio >= 1 ? [(int) round(1000 / $ratio), 1000] : [1000, (int) round(1000 * $ratio)];

            $artwork = Artwork::firstOrCreate(['code' => $code], [
                'title' => $title,
                'artist_id' => $artist->id,
                'description' => trim("{$medium}, {$date}. Reproduced from a public-domain original; image courtesy of {$credit}."),
                'type' => ArtworkType::PrintOpenEdition,
                'style_tags' => array_values(array_unique([str($category->name)->lower()->value(), $w === $h ? 'square' : ($w > $h ? 'landscape' : 'portrait'), 'museum', 'public-domain'])),
                'default_medium_id' => $giclee?->id,
                'native_width_mm' => $w,
                'native_height_mm' => $h,
                'min_width_mm' => 300,
                'max_width_mm' => $w >= $h ? 1800 : 1200,
                'allow_custom_size' => true,
                'pricing_model' => ArtworkPricingModel::PerSquareMetre,
                'price_per_m2' => $perM2,
                'lead_time_days' => 7,
                'is_featured' => $i % 9 === 0,
                'sort_order' => 100 + $i,
            ]);

            if ($artwork->wasRecentlyCreated) {
                $artwork->categories()->syncWithoutDetaching(array_filter([$category->id, $category->parent_id]));
            }

            CatalogAssets::attach($artwork, 'main', CatalogAssets::path("artworks/{$sub}", "{$slug}.jpg"));
        }
    }
}
