<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quote lines are now priced as: cost per piece (artwork + materials at cost) + labour + a margin staff
 * type themselves. Existing lines keep their price: their margin is whatever was above cost and labour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('margin_amount')->nullable()->after('labour_amount');
        });

        $draftQuoteIds = DB::table('quotes')->where('status', 'draft')->pluck('id')->flip();

        foreach (DB::table('quote_lines')->orderBy('id')->get() as $line) {
            $breakdown = json_decode((string) $line->breakdown, true);
            $cost = (int) $line->unit_cost;
            if (is_array($breakdown) && isset($breakdown['components'])) {
                $cost = (int) collect($breakdown['components'])
                    ->sum(fn ($c) => ($c['key'] ?? null) === 'artwork' ? (int) ($c['sell'] ?? 0) : (int) ($c['cost'] ?? 0));
            }
            $margin = max(0, (int) $line->unit_price - $cost - (int) $line->labour_amount);

            DB::table('quote_lines')->where('id', $line->id)->update([
                'unit_cost' => $cost,
                'margin_amount' => $margin,
                // Drafts follow the new rule; sent quotes keep exactly what the customer saw.
                ...($draftQuoteIds->has($line->quote_id) ? ['calculated_unit_price' => $cost + (int) $line->labour_amount + $margin] : []),
            ]);
        }

        // Cost on each quote now includes the artwork and the labour.
        foreach (DB::table('quotes')->pluck('id') as $quoteId) {
            $cost = DB::table('quote_lines')->where('quote_id', $quoteId)
                ->where(fn ($q) => $q->where('is_optional', false)->orWhere('is_selected', true))
                ->get()
                ->sum(fn ($l) => ((int) $l->unit_cost + (int) $l->labour_amount) * (int) $l->quantity);
            DB::table('quotes')->where('id', $quoteId)->update(['total_cost' => $cost]);
        }
    }

    public function down(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->dropColumn('margin_amount');
        });
    }
};
