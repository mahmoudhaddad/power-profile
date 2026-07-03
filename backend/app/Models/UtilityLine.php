<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UtilityLine extends Model
{
    protected $fillable = [
        'name', 'power', 'phases',
        'tariff_per_kwh', 'peak_tariff_per_kwh',
        'peak_hours_start', 'peak_hours_end',
    ];

    protected $casts = [
        'tariff_per_kwh'      => 'float',
        'peak_tariff_per_kwh' => 'float',
        'peak_hours_start'    => 'integer',
        'peak_hours_end'      => 'integer',
    ];

    public function lineable()
    {
        return $this->morphTo();
    }
}
