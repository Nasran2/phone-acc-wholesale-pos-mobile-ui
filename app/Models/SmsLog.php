<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['phone', 'message', 'status', 'response', 'ref_no'])]
class SmsLog extends Model
{
    //
}
