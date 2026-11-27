<?php

namespace Database\Seeders;

use App\Models\Artist;
use Illuminate\Database\Seeder;

/**
 * Demo artist fees (₹ per m² of hand-painted work), priced by how demanding the style is to paint:
 * layered old-master oils and fine miniature brushwork with gold cost the most; flat colour,
 * woodblock prints, bold folk styles and photography the least. Only artists still on the old
 * flat fee (₹60,000) or no fee are changed, so fees staff set by hand are kept.
 */
class ArtistFeeSeeder extends Seeder
{
    /** Artist name => fee in whole rupees per m². */
    public const FEES = [
        // Contemporary demo artists.
        'Asha Menon' => 65_000,              // living abstract painter, layered acrylics
        'Luca Bianchi' => 40_000,            // photographer: printed, little hand work

        // Old masters: many glazed oil layers, fine modelling.
        'Rembrandt van Rijn' => 95_000,
        'Peter Paul Rubens' => 92_000,
        'Jean Léon Gérôme' => 88_000,        // academic, near-invisible brushwork
        'Frederic Edwin Church' => 86_000,   // large detailed panoramas
        'Eugène Delacroix' => 85_000,
        'Albert Bierstadt' => 84_000,
        'Rosa Bonheur' => 80_000,            // detailed animal studies
        'Alphonse Marie Mucha' => 78_000,    // fine line work and ornament
        'Jules Breton' => 74_000,

        // Impressionist and post-impressionist: looser, quicker handling.
        'Vincent van Gogh' => 75_000,        // thick impasto takes paint and time
        'Claude Monet' => 72_000,
        'Édouard Manet' => 70_000,
        'Paul Cezanne' => 68_000,
        'George Inness' => 66_000,
        'Paul Gauguin' => 62_000,
        'John Henry Twachtman' => 58_000,

        // Modern: flat colour and simple shapes.
        'Vasily Kandinsky' => 55_000,
        'Henri Matisse' => 45_000,
        'Joseph Pennell' => 40_000,          // etchings and lithographs

        // Japanese woodblock prints (flat colour, crisp outlines) and brush painting.
        'Gan Ku' => 62_000,
        'Tani Bunchō' => 58_000,
        'Utagawa Kuniyoshi' => 56_000,
        'Watanabe Seitei' => 54_000,
        'Katsushika Hokusai' => 52_000,
        'Utagawa Hiroshige' => 50_000,
        'Utagawa Toyokuni I' => 50_000,
        'Suzuki Harunobu' => 48_000,
        'Isoda Koryusai' => 46_000,

        // Indian miniatures: very fine brushes, tiny detail, often gold.
        'Nainsukh' => 90_000,
        'Mughal-era Delhi-Agra School' => 88_000,
        'Devidasa of Nurpur' => 84_000,
        'Pahari School' => 82_000,
        'Kota Master' => 80_000,
        'Deccan School' => 78_000,
        'Chokha' => 76_000,
        'Nathdwara School' => 72_000,        // pichwai: large but detailed
        'Rajasthani School' => 70_000,
        'Tirupati School, South India' => 65_000,
        'Indian School' => 57_000,
        'Maharashtra School' => 55_000,
        'Shiva and Dayal' => 50_000,
        'Calcutta (Kolkata) School' => 42_000, // Kalighat: bold, quick outlines

        // Himalayan, East Asian and African traditions.
        'Tibetan School' => 76_000,          // thangka detail
        'Nepalese School' => 74_000,
        'Jiang Baohua' => 64_000,
        'Chinese School' => 62_000,
        'Ethiopian Northern Highlands School' => 58_000,
    ];

    public function run(): void
    {
        foreach (self::FEES as $name => $rupees) {
            Artist::query()
                ->where('name', $name)
                ->whereIn('fee_per_m2', [0, 6_000_000])
                ->update(['fee_per_m2' => $rupees * 100]);
        }
    }
}
