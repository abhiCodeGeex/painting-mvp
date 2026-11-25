<?php

namespace App\Models;

use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;

/**
 * Work after the artwork (cutting, mounting, installation…). "amount" is the default per piece;
 * staff can change it on each quote line.
 */
class WorkCharge extends Model
{
    use IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }
}
