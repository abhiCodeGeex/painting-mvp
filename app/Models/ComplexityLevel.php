<?php

namespace App\Models;

use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;

/**
 * Simple / Medium / Complex — price factor for custom creations (sales can override).
 */
class ComplexityLevel extends Model
{
    use IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_factor' => 'decimal:3',
        ];
    }
}
