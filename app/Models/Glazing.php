<?php

namespace App\Models;

use App\Enums\GlazingMaterial;
use App\Enums\ReflectionLevel;
use App\Enums\StockUnit;
use App\Models\Concerns\HasStock;
use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;

class Glazing extends Model
{
    use HasStock, IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'material' => GlazingMaterial::class,
            'reflection_level' => ReflectionLevel::class,
            'cost_per_m2' => 'integer',
            'max_width_mm' => 'integer',
            'max_height_mm' => 'integer',
            'max_area_m2' => 'decimal:3',
        ];
    }

    public function stockUnit(): StockUnit
    {
        return StockUnit::Sheet;
    }

    /**
     * "No glazing" is not a physical item.
     */
    public function tracksStock(): bool
    {
        return $this->material !== GlazingMaterial::None;
    }
}
