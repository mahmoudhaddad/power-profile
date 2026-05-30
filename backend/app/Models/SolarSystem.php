<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolarSystem extends Model
{
    protected $fillable = ['project_id', 'name', 'capacity_kw', 'is_active', 'notes'];

    protected $casts = [
        'capacity_kw' => 'float',
        'is_active'   => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function batteries()
    {
        return $this->hasMany(Battery::class);
    }
}
