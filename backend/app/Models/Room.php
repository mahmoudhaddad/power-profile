<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = ['floor_id', 'name', 'type', 'area', 'power_consumption', 'solar_power', 'existing_solar_power', 'solar_source', 'generator_power', 'work_days', 'work_time_intervals', 'working_season_intervals'];

    protected $casts = [
        'work_days'                 => 'array',
        'work_time_intervals'       => 'array',
        'working_season_intervals'  => 'array',
    ];

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function components()
    {
        return $this->hasMany(RoomComponent::class);
    }

    public function utilityLines()
    {
        return $this->morphMany(UtilityLine::class, 'lineable');
    }

    public function generatorLines()
    {
        return $this->morphMany(GeneratorLine::class, 'generable');
    }

    public function sockets()
    {
        return $this->morphMany(Socket::class, 'socketable');
    }
}
