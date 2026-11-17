<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotations (spec §8). Money = bigint paise. Lines and totals are snapshots:
 * later master price changes never alter a quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->jsonb('estimate_snapshot')->nullable()->after('notes');
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enquiry_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30)->index();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 30)->default('draft');

            $table->date('issue_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('place_of_supply', 2)->nullable()->comment('GST state code of delivery');
            $table->boolean('intra_state')->default(true);
            $table->jsonb('customer_snapshot')->nullable();
            $table->jsonb('delivery_snapshot')->nullable();

            $table->string('lead_time')->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('terms')->nullable();
            $table->text('note_to_customer')->nullable();
            $table->text('internal_note')->nullable();

            $table->unsignedBigInteger('items_total')->default(0);
            $table->unsignedBigInteger('extras_total')->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('taxable')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('cgst')->default(0);
            $table->unsignedBigInteger('sgst')->default(0);
            $table->unsignedBigInteger('igst')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('grand_total')->default(0);
            $table->unsignedBigInteger('total_cost')->default(0);

            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->jsonb('sent_via')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['enquiry_id', 'version']);
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enquiry_line_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('title');
            $table->text('spec')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_cost')->default(0);
            $table->unsignedBigInteger('calculated_unit_price')->default(0);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('line_total')->default(0);
            $table->foreignId('complexity_level_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_selected')->default(true);
            $table->string('override_reason')->nullable();
            $table->jsonb('breakdown')->nullable();
            $table->timestamps();
        });

        Schema::create('quote_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extra_charge_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('name');
            $table->string('inclusion', 20)->default('charged');
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_extras');
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
        Schema::table('enquiries', fn (Blueprint $table) => $table->dropColumn('estimate_snapshot'));
    }
};
