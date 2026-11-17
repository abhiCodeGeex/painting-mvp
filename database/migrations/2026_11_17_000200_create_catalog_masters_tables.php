<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog group (spec §4.2). Money = bigint paise, dimensions = integer mm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('bio')->nullable();
            $table->string('country', 2)->default('IN');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('substrates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('depth_mm')->default(0);
            $table->jsonb('edge_styles')->nullable();
            $table->boolean('allows_glazing')->default(true);
            $table->boolean('allows_mat')->default(true);
            $table->boolean('requires_frame')->default(false);
            $table->unsignedBigInteger('cost_per_m2')->default(0);
            $table->unsignedBigInteger('cost_per_perimeter_m')->default(0);
            $table->unsignedInteger('max_width_mm')->nullable();
            $table->unsignedInteger('max_height_mm')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('mediums', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('kind', 20);
            $table->decimal('price_factor', 6, 3)->default(1);
            $table->unsignedSmallInteger('min_resolution_ppi')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('medium_substrate', function (Blueprint $table) {
            // Explicit table: Laravel would guess "media" (the media library table).
            $table->foreignId('medium_id')->constrained('mediums')->cascadeOnDelete();
            $table->foreignId('substrate_id')->constrained()->cascadeOnDelete();
            $table->primary(['medium_id', 'substrate_id']);
        });

        Schema::create('standard_sizes', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('height_mm');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['width_mm', 'height_mm']);
        });

        Schema::create('artworks', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->foreignId('artist_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('type', 30);
            $table->unsignedInteger('edition_size')->nullable();
            $table->jsonb('style_tags')->nullable();
            $table->foreignId('default_medium_id')->nullable()->constrained('mediums')->nullOnDelete();
            $table->unsignedInteger('native_width_mm')->nullable();
            $table->unsignedInteger('native_height_mm')->nullable();
            $table->unsignedInteger('min_width_mm')->nullable();
            $table->unsignedInteger('min_height_mm')->nullable();
            $table->unsignedInteger('max_width_mm')->nullable();
            $table->unsignedInteger('max_height_mm')->nullable();
            $table->boolean('allow_custom_size')->default(true);
            $table->string('pricing_model', 20);
            $table->unsignedBigInteger('price_per_m2')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('artwork_category', function (Blueprint $table) {
            $table->foreignId('artwork_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->primary(['artwork_id', 'category_id']);
        });

        Schema::create('artwork_medium', function (Blueprint $table) {
            $table->foreignId('artwork_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medium_id')->constrained('mediums')->cascadeOnDelete();
            $table->primary(['artwork_id', 'medium_id']);
        });

        Schema::create('artwork_substrate', function (Blueprint $table) {
            $table->foreignId('artwork_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substrate_id')->constrained()->cascadeOnDelete();
            $table->primary(['artwork_id', 'substrate_id']);
        });

        Schema::create('artwork_size_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artwork_id')->constrained()->cascadeOnDelete();
            $table->foreignId('standard_size_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('height_mm');
            $table->unsignedBigInteger('price');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['artwork_id', 'width_mm', 'height_mm']);
        });
    }

    public function down(): void
    {
        foreach (['artwork_size_prices', 'artwork_substrate', 'artwork_medium', 'artwork_category', 'artworks',
            'standard_sizes', 'medium_substrate', 'mediums', 'substrates', 'categories', 'artists'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function authors(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
    }
};
