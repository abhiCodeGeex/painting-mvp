<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order is placed from an approved quotation and paid in two parts: a token amount, then the
 * full & final balance on delivery. Payments are made offline and confirmed by staff. Money in paise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('enquiry_id')->constrained();
            $table->foreignId('quote_id')->unique()->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->string('status')->default('placed')->index();
            $table->unsignedBigInteger('grand_total');
            $table->unsignedBigInteger('token_amount');
            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('placed_at');
            $table->timestamp('delivered_at')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // token | final
            $table->unsignedBigInteger('amount');
            $table->string('status')->default('due')->index();
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->date('paid_on')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('orders');
    }
};
