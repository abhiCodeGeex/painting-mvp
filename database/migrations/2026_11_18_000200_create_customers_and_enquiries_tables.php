<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2: customers, addresses, enquiries, enquiry lines, messages, status history, numbering.
 * Money = bigint paise, dimensions = integer mm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('individual');
            $table->string('company_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('website')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('enquiry_sources')->nullOnDelete();
            $table->boolean('tax_exempt')->default(false);
            $table->string('tax_exempt_note')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('marketing_consent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('delivery');
            $table->string('label')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');
            $table->string('pin_code', 6);
            $table->foreignId('state_id')->constrained()->restrictOnDelete();
            $table->string('country_code', 2)->default('IN');
            $table->string('access_notes')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
        });

        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('project_name');
            $table->string('project_type', 20)->default('home');
            $table->foreignId('source_id')->nullable()->constrained('enquiry_sources')->nullOnDelete();
            $table->foreignId('delivery_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->date('required_by')->nullable();
            $table->string('budget_range', 20)->nullable();
            $table->boolean('installation_needed')->default(false);
            $table->string('preferred_contact', 20)->default('email');
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('priority', 10)->default('normal');
            $table->text('internal_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_customer_activity_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'assigned_to']);
        });

        Schema::create('enquiry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('source_type', 20);
            $table->foreignId('artwork_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->jsonb('custom_brief')->nullable();
            $table->jsonb('own_artwork')->nullable();
            $table->timestamp('rights_confirmed_at')->nullable();
            $table->string('unit', 5)->default('cm');
            $table->string('size_reference', 10)->default('image');
            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('height_mm');
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('quality_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('medium_id')->nullable()->constrained('mediums')->nullOnDelete();
            $table->foreignId('substrate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('edge_style', 20)->nullable();
            $table->foreignId('frame_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('frame_advice_requested')->default(false);
            $table->foreignId('mat_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('mat_border_mm')->nullable();
            $table->foreignId('glazing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('placement')->nullable();
            $table->unsignedInteger('wall_width_mm')->nullable();
            $table->unsignedInteger('wall_height_mm')->nullable();
            $table->string('wall_colour', 7)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('enquiry_line_finishing', function (Blueprint $table) {
            $table->foreignId('enquiry_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finishing_id')->constrained()->cascadeOnDelete();
            $table->primary(['enquiry_line_id', 'finishing_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('message');
            $table->text('body');
            $table->boolean('from_staff')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['enquiry_id', 'created_at']);
        });

        Schema::create('status_histories', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('from', 30)->nullable();
            $table->string('to', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['status_histories', 'messages', 'enquiry_line_finishing', 'enquiry_lines', 'enquiries',
            'document_sequences', 'addresses', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
