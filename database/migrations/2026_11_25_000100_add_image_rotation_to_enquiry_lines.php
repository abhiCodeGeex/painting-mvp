<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer can turn a picture by quarter turns (clockwise degrees: 0, 90, 180, 270).
     * The whole image is turned and hung sideways, never cropped.
     */
    public function up(): void
    {
        Schema::table('enquiry_lines', function (Blueprint $table) {
            $table->smallInteger('image_rotation')->default(0)->after('height_mm');
        });
    }

    public function down(): void
    {
        Schema::table('enquiry_lines', fn (Blueprint $table) => $table->dropColumn('image_rotation'));
    }
};
