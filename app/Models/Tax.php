<?php

namespace App\Models;

use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;

/**
 * GST rate. At quote time: same state as the company → CGST + SGST (half each), else IGST.
 */
class Tax extends Model
{
    use IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Only one tax can be the default.
        static::saved(function (Tax $tax): void {
            if ($tax->is_default) {
                static::whereKeyNot($tax->getKey())->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }
}
