<?php

namespace Database\Seeders;

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
                DemoEnquirySeeder::class,
            ]);
        }
    }
}
