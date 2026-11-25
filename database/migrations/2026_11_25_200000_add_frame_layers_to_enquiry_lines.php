<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Several frames on one picture: up to 3 stacked layers (innermost first), each optionally split
     * between two mouldings. frame_id stays the first layer's frame; null here = one plain frame_id layer.
     * Shape: [{frame_id: int, split: null|{frame_id: int, mode: 'top_bottom_sides'|'halves'}}].
     */
    public function up(): void
    {
        Schema::table('enquiry_lines', function (Blueprint $table) {
            $table->jsonb('frame_layers')->nullable()->after('frame_id');
        });
    }

    public function down(): void
    {
        Schema::table('enquiry_lines', fn (Blueprint $table) => $table->dropColumn('frame_layers'));
    }
};
