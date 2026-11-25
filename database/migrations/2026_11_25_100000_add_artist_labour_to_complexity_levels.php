<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Artist labour for a new creation, per complexity level: what we pay the artist per m² of painting,
 * with a minimum per piece. It is a cost; the pricing engine marks it up like materials.
 */
return new class extends Migration
{
    /** Defaults for the seeded levels (paise). */
    private const DEFAULTS = [
        'Simple' => [600_000, 150_000],   // ₹6,000 per m², min ₹1,500
        'Medium' => [1_000_000, 250_000], // ₹10,000 per m², min ₹2,500
        'Complex' => [1_600_000, 400_000], // ₹16,000 per m², min ₹4,000
    ];

    public function up(): void
    {
        Schema::table('complexity_levels', function (Blueprint $table) {
            $table->unsignedBigInteger('artist_rate_per_m2')->default(0)->after('price_factor');
            $table->unsignedBigInteger('artist_minimum')->default(0)->after('artist_rate_per_m2');
        });

        foreach (self::DEFAULTS as $name => [$rate, $minimum]) {
            DB::table('complexity_levels')->where('name', $name)->where('artist_rate_per_m2', 0)
                ->update(['artist_rate_per_m2' => $rate, 'artist_minimum' => $minimum]);
        }
    }

    public function down(): void
    {
        Schema::table('complexity_levels', function (Blueprint $table) {
            $table->dropColumn(['artist_rate_per_m2', 'artist_minimum']);
        });
    }
};
