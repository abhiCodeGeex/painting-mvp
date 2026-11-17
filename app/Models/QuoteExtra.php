<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Packing, shipping, installation… on a quote. Only "charged" extras add to the total;
 * "included" and "excluded" are shown for clarity.
 */
class QuoteExtra extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function inclusionLabel(): string
    {
        return match ($this->inclusion) {
            'included' => 'Included',
            'excluded' => 'Not included',
            default => 'Charged',
        };
    }
}
