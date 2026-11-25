<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work done after the artwork (cutting, mounting, installation…): a master list with default
 * amounts, and per quote line the work chosen with the amount agreed on that quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_charges', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('amount')->default(0); // paise, per piece
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // [{work_charge_id, name, amount (paise per piece)}]
        Schema::table('quote_lines', fn (Blueprint $table) => $table->jsonb('work_items')->nullable()->after('labour_amount'));

        $now = now();
        foreach (self::defaults() as $i => [$name, $amount, $description]) {
            DB::table('work_charges')->insert(['name' => $name, 'amount' => $amount, 'description' => $description,
                'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('quote_lines', fn (Blueprint $table) => $table->dropColumn('work_items'));
        Schema::dropIfExists('work_charges');
    }

    /** @return list<array{0: string, 1: int, 2: string}> */
    public static function defaults(): array
    {
        return [
            ['Installation', 300_000, 'Hanging the finished piece at the customer\'s site.'],
            ['Cutting & trimming', 50_000, 'Cutting the print or canvas to the final size.'],
            ['Mounting', 80_000, 'Mounting the artwork on its backing board.'],
            ['Stretching', 60_000, 'Stretching canvas over the stretcher bars.'],
            ['Wall preparation', 150_000, 'Marking, drilling and fixing hardware on site.'],
        ];
    }
};
