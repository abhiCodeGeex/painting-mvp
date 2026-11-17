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

    protected $attributes = ['discount_amount' => 0, 'is_optional' => false, 'is_selected' => true];

    protected static function booted(): void
    {
        static::saving(function (QuoteLine $line): void {
            $line->discount_amount ??= 0;
            $line->unit_price ??= 0;
        });
    }

    protected function casts(): array
    {
        return [
            'breakdown' => 'array',
            'is_optional' => 'boolean',
            'is_selected' => 'boolean',
            'quantity' => 'integer',
            'unit_cost' => 'integer',
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
