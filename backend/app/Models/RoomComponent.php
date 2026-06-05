<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomComponent extends Model
{
    protected $fillable = [
        'room_id', 'component_type_id', 'power', 'phases', 'phase', 'power_factor',
        'quantity', 'group_name', 'priority', 'needs_socket',
        'usage_season', 'usage_day_type', 'usage_time_intervals',
        'load_flexibility', 'required_run_hours', 'earliest_start_hour',
        'latest_end_hour', 'min_continuous_run', 'max_interruptions', 'curtail_min_pct',
    ];

    protected $casts = [
        'needs_socket'        => 'boolean',
        'usage_time_intervals'=> 'array',
        'required_run_hours'  => 'integer',
        'earliest_start_hour' => 'integer',
        'latest_end_hour'     => 'integer',
        'min_continuous_run'  => 'integer',
        'max_interruptions'   => 'integer',
        'curtail_min_pct'     => 'integer',
    ];

    public function componentType()
    {
        return $this->belongsTo(ComponentType::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
