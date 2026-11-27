<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A priced artwork on a quote. "breakdown" is the pricing engine snapshot (admin only).
 */
class QuoteLine extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['discount_amount' => 0, 'labour_amount' => 0, 'is_optional' => false, 'is_selected' => true];

    protected static function booted(): void
    {
        static::saving(function (QuoteLine $line): void {
            $line->discount_amount ??= 0;
            $line->labour_amount ??= 0;
            $line->unit_price ??= 0;
            // A re-priced line is worked out without the old "work after the artwork" list, so keeping
            // the list would show the customer charges that are no longer in the price.
            if ($line->exists && $line->isDirty('calculated_unit_price')) {
                $line->work_items = null;
            }
        });
    }

    /**
     * The artist chosen for a new creation: their fee per m² prices the artwork.
     *
     * @return BelongsTo<Artist, $this>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * Work after the artwork on this line (cutting, mounting, installation…), per piece, in paise.
     *
     * @param  array<int|string, mixed>|null  $items
     */
    public static function workTotalOf(?array $items): int
    {
        return (int) collect($items ?? [])->sum(fn ($i) => max(0, (int) ($i['amount'] ?? 0)));
    }

    public function workTotal(): int
    {
        return self::workTotalOf($this->work_items);
    }

    protected function casts(): array
    {
        return [
            'breakdown' => 'array',
            'work_items' => 'array',
            'is_optional' => 'boolean',
            'is_selected' => 'boolean',
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'labour_amount' => 'integer',
            'margin_amount' => 'integer',
            'calculated_unit_price' => 'integer',
            'unit_price' => 'integer',
            'discount_amount' => 'integer',
            'line_total' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<EnquiryLine, $this>
     */
    public function enquiryLine(): BelongsTo
    {
        return $this->belongsTo(EnquiryLine::class);
    }

    public function isOverridden(): bool
    {
        return $this->calculated_unit_price > 0 && $this->unit_price !== $this->calculated_unit_price;
    }

    /** Counted in the totals (optional lines only when the customer keeps them). */
    public function counts(): bool
    {
        return ! $this->is_optional || $this->is_selected;
    }
}
