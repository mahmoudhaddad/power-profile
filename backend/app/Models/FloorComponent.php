<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FloorComponent extends Model
{
    protected $fillable = ['floor_id', 'component_type_id', 'power', 'phases', 'phase', 'power_factor', 'quantity', 'group_name', 'priority', 'needs_socket', 'usage_season', 'usage_day_type', 'usage_time_intervals'];

    protected $casts = ['needs_socket' => 'boolean', 'usage_time_intervals' => 'array'];

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function componentType()
    {
        return $this->belongsTo(ComponentType::class);
    }
}
