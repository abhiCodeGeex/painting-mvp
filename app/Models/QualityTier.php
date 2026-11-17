<?php

namespace App\Models;

use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Standard / Premium / Museum: a price factor plus default choices for the enquiry form.
 */
class QualityTier extends Model
{
    use IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_factor' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Medium, $this>
     */
    public function defaultMedium(): BelongsTo
    {
        return $this->belongsTo(Medium::class, 'default_medium_id');
    }

    /**
     * @return BelongsTo<Substrate, $this>
     */
    public function defaultSubstrate(): BelongsTo
    {
        return $this->belongsTo(Substrate::class, 'default_substrate_id');
    }

    /**
     * @return BelongsTo<Glazing, $this>
     */
    public function defaultGlazing(): BelongsTo
    {
        return $this->belongsTo(Glazing::class, 'default_glazing_id');
    }

    /**
     * @return BelongsTo<Mat, $this>
     */
    public function defaultMat(): BelongsTo
    {
        return $this->belongsTo(Mat::class, 'default_mat_id');
    }
}
