<?php

namespace App\Models;

use App\Services\BatteryChemistryService;
use Illuminate\Database\Eloquent\Model;

class Battery extends Model
{
    protected $fillable = [
        'project_id', 'name', 'chemistry',
        'nominal_voltage_v', 'capacity_ah_per_unit', 'quantity',
        'series_count', 'parallel_count', 'installation_date',
        'depth_of_discharge', 'round_trip_efficiency',
        'c_rate_charge', 'c_rate_discharge', 'rated_cycle_life',
        'current_soc', 'is_active', 'notes', 'solar_system_id',
    ];

    protected $casts = [
        'installation_date'     => 'date',
        'is_active'             => 'boolean',
        'nominal_voltage_v'     => 'float',
        'capacity_ah_per_unit'  => 'float',
        'quantity'              => 'integer',
        'series_count'          => 'integer',
        'parallel_count'        => 'integer',
        'depth_of_discharge'    => 'float',
        'round_trip_efficiency' => 'float',
        'c_rate_charge'         => 'float',
        'c_rate_discharge'      => 'float',
        'rated_cycle_life'      => 'integer',
        'current_soc'           => 'float',
        'solar_system_id'       => 'integer',
    ];

    protected $appends = [
        'age_years',
        'nominal_capacity_kwh',
        'age_factor',
        'usable_capacity_kwh',
        'current_available_kwh',
        'max_charge_power_kw',
        'max_discharge_power_kw',
        'runtime_hours_at_max_discharge',
        'runtime_hours_at_average_discharge',
        'current_runtime_hours',
        'health_status',
        'remaining_calendar_life_years',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function solarSystem()
    {
        return $this->belongsTo(SolarSystem::class);
    }

    // ── Computed accessors (never stored) ────────────────────────────────────

    public function getAgeYearsAttribute(): float
    {
        $days = \Carbon\Carbon::parse($this->getRawOriginal('installation_date'))->diffInDays(now());
        return round($days / 365.25, 2);
    }

    public function getNominalCapacityKwhAttribute(): float
    {
        return ($this->nominal_voltage_v
            * $this->capacity_ah_per_unit
            * $this->quantity) / 1000.0;
    }

    public function getAgeFactorAttribute(): float
    {
        $defaults    = BatteryChemistryService::getDefaults($this->chemistry);
        $degradation = $defaults['degradation_per_year'] ?? 0.03;
        $factor      = 1.0 - ($this->age_years * $degradation);
        return max(0.70, $factor); // industry replace threshold: 70%
    }

    public function getUsableCapacityKwhAttribute(): float
    {
        return $this->nominal_capacity_kwh
            * $this->depth_of_discharge
            * $this->age_factor;
    }

    public function getCurrentAvailableKwhAttribute(): float
    {
        $soc = max(0.0, min(1.0, (float) $this->current_soc));
        return $this->usable_capacity_kwh * $soc;
    }

    public function getMaxChargePowerKwAttribute(): float
    {
        return $this->nominal_capacity_kwh * $this->c_rate_charge;
    }

    public function getMaxDischargePowerKwAttribute(): float
    {
        return $this->nominal_capacity_kwh * $this->c_rate_discharge;
    }

    public function getRuntimeHoursAtMaxDischargeAttribute(): float
    {
        if ($this->max_discharge_power_kw <= 0) return 0;
        return round($this->usable_capacity_kwh / $this->max_discharge_power_kw, 2);
    }

    public function getRuntimeHoursAtAverageDischargeAttribute(): float
    {
        $avg = max(0.01, $this->max_discharge_power_kw / 2);
        return round($this->usable_capacity_kwh / $avg, 2);
    }

    public function getCurrentRuntimeHoursAttribute(): float
    {
        $avg = max(0.01, $this->max_discharge_power_kw / 2);
        return round($this->current_available_kwh / $avg, 2);
    }

    public function getHealthStatusAttribute(): string
    {
        $f = $this->age_factor;
        if ($f >= 0.90) return 'good';
        if ($f >= 0.80) return 'fair';
        if ($f >= 0.70) return 'degraded';
        return 'replace';
    }

    public function getRemainingCalendarLifeYearsAttribute(): float
    {
        $defaults = BatteryChemistryService::getDefaults($this->chemistry);
        $calLife  = $defaults['calendar_life_years'] ?? 10;
        return round(max(0, $calLife - $this->age_years), 2);
    }
}
