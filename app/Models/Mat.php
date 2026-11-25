<?php

namespace App\Models;

use App\Enums\StockUnit;
use App\Models\Concerns\HasStock;
use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;

/**
 * Mat / passe-partout board.
 */
class Mat extends Model
{
    use HasStock, IsMaster;

    public function stockUnit(): StockUnit
    {
        return StockUnit::Sheet;
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'thickness_mm' => 'decimal:1',
            'default_border_mm' => 'integer',
            'bottom_weight_mm' => 'integer',
            'cost_per_m2' => 'integer',
            'cut_fee' => 'integer',
            'max_sheet_width_mm' => 'integer',
            'max_sheet_height_mm' => 'integer',
        ];
    }
}
