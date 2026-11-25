<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Artist fee per m² on each artist (replaces the shop-wide fee), the artist chosen for a new creation
 * on a quote line, and one manual labour amount per quote line (paise, per piece).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->unsignedBigInteger('fee_per_m2')->default(0)->after('country');
        });

        // Existing artists start at the old shop-wide fee (₹60,000 per m²) so current prices don't collapse.
        DB::table('artists')->where('fee_per_m2', 0)->update(['fee_per_m2' => 6_000_000]);

        Schema::table('quote_lines', function (Blueprint $table) {
            $table->foreignId('artist_id')->nullable()->after('complexity_level_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('labour_amount')->default(0)->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('artist_id');
            $table->dropColumn('labour_amount');
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropColumn('fee_per_m2');
        });
    }
};
