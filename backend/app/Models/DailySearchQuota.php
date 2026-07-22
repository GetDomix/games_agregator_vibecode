<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySearchQuota extends Model
{
    protected $fillable = [
        'quota_key',
        'day',
        'count',
    ];
}
