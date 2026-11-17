<?php

namespace App\Models;

use App\Enums\PricingType;
use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Finishing & hardware: varnish, D-rings, French cleat, dust cover…
 */
class Finishing extends Model
{
    use IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pricing_type' => PricingType::class,
            'cost' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Substrate, $this>
     */
    public function substrates(): BelongsToMany
    {
        return $this->belongsToMany(Substrate::class);
    }
}
