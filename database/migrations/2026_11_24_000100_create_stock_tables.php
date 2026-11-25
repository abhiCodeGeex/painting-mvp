<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock on hand for the physical materials we hold: frame mouldings (metres), mat boards,
 * glazing sheets and substrates. One stock row per item, plus a movement log of every change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->morphs('stockable');
            $table->decimal('quantity', 10, 2)->default(0);
            $table->decimal('low_stock_threshold', 10, 2)->default(20);
            $table->string('unit', 10);
            $table->timestamps();
            $table->unique(['stockable_type', 'stockable_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('change', 10, 2);
            $table->decimal('quantity_after', 10, 2);
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Existing items start with no stock counted; staff enter the opening count.
        $now = now();
        $rows = [];
        foreach (DB::table('frames')->pluck('id') as $id) {
            $rows[] = ['stockable_type' => 'App\Models\Frame', 'stockable_id' => $id, 'unit' => 'm'];
        }
        foreach (DB::table('mats')->pluck('id') as $id) {
            $rows[] = ['stockable_type' => 'App\Models\Mat', 'stockable_id' => $id, 'unit' => 'sheet'];
        }
        foreach (DB::table('glazings')->where('material', '!=', 'none')->pluck('id') as $id) {
            $rows[] = ['stockable_type' => 'App\Models\Glazing', 'stockable_id' => $id, 'unit' => 'sheet'];
        }
        foreach (DB::table('substrates')->get(['id', 'unit']) as $substrate) {
            $rows[] = ['stockable_type' => 'App\Models\Substrate', 'stockable_id' => $substrate->id, 'unit' => $substrate->unit ?: 'sheet'];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('stock_items')->insert(array_map(
                fn (array $row) => $row + ['quantity' => 0, 'low_stock_threshold' => 20, 'created_at' => $now, 'updated_at' => $now],
                $chunk,
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_items');
    }
};
