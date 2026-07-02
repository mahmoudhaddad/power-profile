<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolarSystem extends Model
{
    protected $fillable = [
        'project_id', 'name', 'capacity_kw', 'is_active', 'notes',
        'installation_cost', 'annual_maintenance_cost', 'panel_lifetime_years',
    ];

    protected $casts = [
        'capacity_kw'              => 'float',
        'is_active'                => 'boolean',
        'installation_cost'        => 'float',
        'annual_maintenance_cost'  => 'float',
        'panel_lifetime_years'     => 'integer',
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
