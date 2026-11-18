<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Substrate specifications (material, thickness/GSM, finish, printing methods, sizes,
 * supplier, unit) and a "material" line for categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('substrates', function (Blueprint $table) {
            $table->string('material_type', 20)->nullable()->after('name');
            $table->decimal('thickness_mm', 5, 1)->nullable()->after('material_type');
            $table->unsignedSmallInteger('gsm')->nullable()->after('thickness_mm');
            $table->string('finish', 20)->nullable()->after('gsm');
            $table->jsonb('printing_methods')->nullable()->after('finish');
            $table->jsonb('available_sizes')->nullable()->after('printing_methods');
            $table->string('supplier')->nullable()->after('available_sizes');
            $table->string('unit', 10)->nullable()->after('supplier');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('material')->nullable()->after('slug');
        });

        // Specifications for the substrates created by the starter data.
        $sizes = json_encode(['12×18', '18×24', '24×36']);
        $specs = [
            'Stretched canvas 38 mm' => ['canvas', 0.5, 380, 'matte', ['inkjet', 'uv'], 'm2'],
            'Stretched canvas 20 mm' => ['canvas', 0.5, 380, 'matte', ['inkjet', 'uv'], 'm2'],
            'Canvas roll (unstretched)' => ['canvas', 0.5, 380, 'matte', ['inkjet', 'uv'], 'roll'],
            'Fine-art paper 310 gsm' => ['paper', 0.5, 310, 'matte', ['inkjet'], 'sheet'],
            'Wood panel 18 mm' => ['wood', 18, null, 'textured', ['uv'], 'sheet'],
        ];
        foreach ($specs as $name => [$material, $thickness, $gsm, $finish, $methods, $unit]) {
            DB::table('substrates')->where('name', $name)->update([
                'material_type' => $material,
                'thickness_mm' => $thickness,
                'gsm' => $gsm,
                'finish' => $finish,
                'printing_methods' => json_encode($methods),
                'available_sizes' => $sizes,
                'unit' => $unit,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('material'));
        Schema::table('substrates', fn (Blueprint $table) => $table->dropColumn([
            'material_type', 'thickness_mm', 'gsm', 'finish', 'printing_methods', 'available_sizes', 'supplier', 'unit',
        ]));
    }
};
