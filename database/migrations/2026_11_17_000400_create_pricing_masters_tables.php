<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing group: GST taxes, extra charges, custom-creation complexity levels.
 * Pricing rules themselves live in settings (App\Settings\PricingSettings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('rate_percent', 5, 2);
            $table->string('hsn_sac_code', 10)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('extra_charges', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 20);
            $table->string('pricing_type', 20);
            $table->unsignedBigInteger('cost')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('complexity_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('price_factor', 6, 3)->default(1);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $this->authors($table);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complexity_levels');
        Schema::dropIfExists('extra_charges');
        Schema::dropIfExists('taxes');
    }

    private function authors(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
    }
};
