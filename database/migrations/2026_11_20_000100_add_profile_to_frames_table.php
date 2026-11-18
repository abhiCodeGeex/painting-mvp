<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moulding cross-section for the 3D preview (flat, rounded, scoop, stepped, ornate, floater).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('frames', function (Blueprint $table) {
            $table->string('profile', 20)->default('flat')->after('material');
        });

        // Sensible shapes for existing frames.
        DB::table('frames')->where('is_floater', true)->update(['profile' => 'floater']);
        DB::table('frames')->where('name', 'ilike', '%ornate%')->update(['profile' => 'ornate']);
        DB::table('frames')->where('code', 'FR-WAL-30')->update(['profile' => 'scoop']);
        DB::table('frames')->where('code', 'FR-OAK-25')->update(['profile' => 'rounded']);
        DB::table('frames')->where('code', 'FR-ALU-15')->update(['profile' => 'stepped']);
    }

    public function down(): void
    {
        Schema::table('frames', fn (Blueprint $table) => $table->dropColumn('profile'));
    }
};
