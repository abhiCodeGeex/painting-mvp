<?php

namespace App\Models;

use App\Enums\MediumKind;
use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Oil, acrylic, watercolour, giclée print… (table "mediums" — Laravel would pluralise to
 * "media", which is the media library's table).
 */
class Medium extends Model
{
    use IsMaster;

    protected $table = 'mediums';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => MediumKind::class,
            'price_factor' => 'decimal:3',
            'min_resolution_ppi' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Substrate, $this>
     */
    public function substrates(): BelongsToMany
    {
        return $this->belongsToMany(Substrate::class, 'medium_substrate');
    }
}
