<?php

namespace App\Models;

use App\Enums\ExtraChargeBasis;
use App\Enums\ExtraChargeType;
use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;

/**
 * Packing, shipping, installation and other charges that can be added to a quote.
 */
class ExtraCharge extends Model
{
    use IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ExtraChargeType::class,
            'pricing_type' => ExtraChargeBasis::class,
            'cost' => 'integer',
        ];
    }
}
