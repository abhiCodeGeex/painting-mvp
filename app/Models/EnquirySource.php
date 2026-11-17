<?php

namespace App\Models;

use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;

class EnquirySource extends Model
{
    use IsMaster;

    protected $guarded = ['id'];
}
