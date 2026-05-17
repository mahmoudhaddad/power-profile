<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComponentType extends Model
{
    protected $fillable = ['name', 'is_preset', 'default_power', 'default_phases', 'default_power_factor', 'default_needs_socket', 'default_usage_season', 'default_usage_day_type', 'default_usage_time_intervals'];

    public function roomComponents()
    {
        return $this->hasMany(RoomComponent::class);
    }
}
