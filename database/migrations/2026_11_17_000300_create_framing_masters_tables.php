<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Framing group (spec §4.2). Money = bigint paise, dimensions = integer mm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frames', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('supplier_code')->nullable();
            $table->unsignedSmallInteger('profile_width_mm');
            $table->unsignedSmallInteger('profile_depth_mm');
            $table->unsignedSmallInteger('rebate_depth_mm')->nullable();
            $table->string('colour_hex', 7);
            $table->string('finish', 20);
            $table->string('material', 20);
            $table->unsignedBigInteger('price_per_m');
            $table->decimal('waste_percent', 5, 2)->default(10);
            $table->unsignedInteger('min_inner_width_mm')->nullable();
            $table->unsignedInteger('min_inner_height_mm')->nullable();
            $table->unsignedInteger('max_inner_width_mm')->nullable();
            $table->unsignedInteger('max_inner_height_mm')->nullable();
            $table->unsignedInteger('max_perimeter_mm')->nullable();
            $table->boolean('is_floater')->default(false);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('frame_substrate', function (Blueprint $table) {
            $table->foreignId('frame_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substrate_id')->constrained()->cascadeOnDelete();
            $table->primary(['frame_id', 'substrate_id']);
        });

        Schema::create('mats', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('colour_hex', 7);
            $table->string('core_colour_hex', 7)->default('#FFFFFF');
            $table->decimal('thickness_mm', 4, 1)->default(1.4);
            $table->unsignedSmallInteger('default_border_mm')->default(50);
            $table->unsignedSmallInteger('bottom_weight_mm')->default(0);
            $table->unsignedBigInteger('cost_per_m2');
            $table->unsignedBigInteger('cut_fee')->default(0);
            $table->unsignedInteger('max_sheet_width_mm')->nullable();
            $table->unsignedInteger('max_sheet_height_mm')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('glazings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('material', 20);
            $table->string('reflection_level', 20);
            $table->unsignedBigInteger('cost_per_m2')->default(0);
            $table->unsignedInteger('max_width_mm')->nullable();
            $table->unsignedInteger('max_height_mm')->nullable();
            $table->decimal('max_area_m2', 6, 3)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('finishings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('pricing_type', 20);
            $table->unsignedBigInteger('cost')->default(0);
            $table->boolean('is_default')->default(false);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('finishing_substrate', function (Blueprint $table) {
            $table->foreignId('finishing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substrate_id')->constrained()->cascadeOnDelete();
            $table->primary(['finishing_id', 'substrate_id']);
        });

        Schema::create('quality_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->decimal('price_factor', 6, 3)->default(1);
            $table->foreignId('default_medium_id')->nullable()->constrained('mediums')->nullOnDelete();
            $table->foreignId('default_substrate_id')->nullable()->constrained('substrates')->nullOnDelete();
            $table->foreignId('default_glazing_id')->nullable()->constrained('glazings')->nullOnDelete();
            $table->foreignId('default_mat_id')->nullable()->constrained('mats')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        foreach (['quality_tiers', 'finishing_substrate', 'finishings', 'glazings', 'mats', 'frame_substrate', 'frames'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function authors(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
    }
};
