<?php

namespace App\Models;

use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;

/**
 * Simple / Medium / Complex — price factor for custom creations (sales can override),
 * plus the artist labour we pay for it (cost per m² of painting, with a minimum per piece).
 */
class ComplexityLevel extends Model
{
    use IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_factor' => 'decimal:3',
            'artist_rate_per_m2' => 'integer',
            'artist_minimum' => 'integer',
        ];
    }
}
