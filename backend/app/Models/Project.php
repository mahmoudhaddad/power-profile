<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = ['user_id', 'name', 'building_type', 'current_step', 'buildings_count', 'total_power', 'solar_power', 'existing_solar_power', 'solar_source', 'generator_source', 'generator_power', 'auto_backup_interval', 'last_auto_backup_at', 'work_days', 'work_time_intervals', 'working_season_intervals', 'location_lat', 'location_lng', 'location_name'];

    protected $casts = [
        'last_auto_backup_at'        => 'datetime',
        'work_days'                  => 'array',
        'work_time_intervals'        => 'array',
        'working_season_intervals'   => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function buildings()
    {
        return $this->hasMany(Building::class);
    }

    public function components()
    {
        return $this->hasMany(ProjectComponent::class);
    }

    public function utilityLines()
    {
        return $this->morphMany(UtilityLine::class, 'lineable');
    }

    public function generatorLines()
    {
        return $this->morphMany(GeneratorLine::class, 'generable');
    }

    public function batteries()
    {
        return $this->hasMany(Battery::class);
    }

    public function solarSystems()
    {
        return $this->hasMany(SolarSystem::class);
    }

    public function projectUsers()
    {
        return $this->hasMany(ProjectUser::class);
    }

    public function sockets()
    {
        return $this->morphMany(Socket::class, 'socketable');
    }

    /**
     * Returns 'admin', 'main', 'normal', or null (no access).
     */
    public function userRole(int $userId): ?string
    {
        if ((int) $this->user_id === $userId) {
            return 'admin';
        }

        $member = $this->projectUsers()->where('user_id', $userId)->first();

        return $member?->role;
    }
}
