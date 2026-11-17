<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fixed selling price of a catalog artwork at one size (paise).
 */
class ArtworkSizePrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'price' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Picking a standard size fills in its dimensions.
        static::saving(function (ArtworkSizePrice $row): void {
            if ($row->standard_size_id && ($row->isDirty('standard_size_id') || ! $row->width_mm)) {
                $size = StandardSize::withTrashed()->find($row->standard_size_id);
                if ($size) {
                    $row->width_mm = $size->width_mm;
                    $row->height_mm = $size->height_mm;
                }
            }
        });
    }

    /**
     * @return BelongsTo<Artwork, $this>
     */
    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class);
    }

    /**
     * @return BelongsTo<StandardSize, $this>
     */
    public function standardSize(): BelongsTo
    {
        return $this->belongsTo(StandardSize::class);
    }
}
