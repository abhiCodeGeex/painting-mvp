<?php

namespace App\Models;

use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;

class RejectionReason extends Model
{
    use IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requires_comment' => 'boolean',
        ];
    }
}
