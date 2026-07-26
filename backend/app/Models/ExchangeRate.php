<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = ['currency', 'rub_per_unit', 'observed_at'];

    protected function casts(): array
    {
        return ['rub_per_unit' => 'float', 'observed_at' => 'datetime'];
    }
}
