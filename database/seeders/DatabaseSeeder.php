<?php

namespace Database\Seeders;

use App\Models\Artwork;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            StateSeeder::class,
            MasterDataSeeder::class,
            CategorySeeder::class,
        ]);

        // Demo catalog, customer and enquiries for local development only.
        if (app()->environment('local')) {
            $this->call([
                DemoCatalogSeeder::class,
                MuseumArtworkSeeder::class,
                DemoEnquirySeeder::class,
                DemoStockSeeder::class,
            ]);
            // The catalogue shows real paintings only: the generated demo pictures stay for the demo
            // enquiries (and tests) but are hidden from customers.
            Artwork::query()->where('code', 'like', 'ART-%')->update(['is_active' => false]);
        }
    }
}
