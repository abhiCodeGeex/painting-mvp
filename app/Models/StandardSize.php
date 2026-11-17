<?php

namespace App\Models;

use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;

class StandardSize extends Model
{
    use IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'width_mm' => 'integer',
            'height_mm' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (StandardSize $size): void {
            if (blank($size->label)) {
                $size->label = self::defaultLabel($size->width_mm, $size->height_mm);
            }
        });
    }

    /**
     * e.g. 500 × 700 mm → "50 × 70 cm".
     */
    public static function defaultLabel(int $widthMm, int $heightMm): string
    {
        $cm = fn (int $mm): string => rtrim(rtrim(number_format($mm / 10, 1, '.', ''), '0'), '.');

        return $cm($widthMm).' × '.$cm($heightMm).' cm';
    }
}
