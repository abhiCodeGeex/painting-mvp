<?php

namespace App\Models;

use App\Enums\SubstrateFinish;
use App\Enums\SubstrateMaterial;
use App\Enums\SubstrateUnit;
use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * What the artwork is on: stretched canvas, fine-art paper, wood panel, dibond…
 */
class Substrate extends Model
{
    use IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'edge_styles' => 'array',
            'material_type' => SubstrateMaterial::class,
            'finish' => SubstrateFinish::class,
            'unit' => SubstrateUnit::class,
            'printing_methods' => 'array',
            'available_sizes' => 'array',
            'thickness_mm' => 'decimal:1',
            'gsm' => 'integer',
            'allows_glazing' => 'boolean',
            'allows_mat' => 'boolean',
            'requires_frame' => 'boolean',
            'depth_mm' => 'integer',
            'cost_per_m2' => 'integer',
            'cost_per_perimeter_m' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Medium, $this>
     */
    public function mediums(): BelongsToMany
    {
        return $this->belongsToMany(Medium::class, 'medium_substrate');
    }

    /**
     * @return BelongsToMany<Frame, $this>
     */
    public function frames(): BelongsToMany
    {
        return $this->belongsToMany(Frame::class);
    }

    /**
     * @return BelongsToMany<Finishing, $this>
     */
    public function finishings(): BelongsToMany
    {
        return $this->belongsToMany(Finishing::class);
    }
}
