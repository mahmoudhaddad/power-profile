<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomComponent extends Model
{
    protected $fillable = ['room_id', 'component_type_id', 'power', 'phases', 'power_factor', 'quantity', 'group_name', 'priority', 'needs_socket', 'usage_season', 'usage_day_type', 'usage_time_intervals'];

    protected $casts = ['needs_socket' => 'boolean', 'usage_time_intervals' => 'array'];

    public function componentType()
    {
        return $this->belongsTo(ComponentType::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
