<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneratorLine extends Model
{
    protected $fillable = [
        'name', 'power', 'phases',
        'fuel_cost_per_liter', 'fuel_consumption_lph',
        'no_load_fuel_lph', 'min_load_pct', 'optimal_load_pct',
    ];

    protected $casts = [
        'fuel_cost_per_liter'  => 'float',
        'fuel_consumption_lph' => 'float',
        'no_load_fuel_lph'     => 'float',
        'min_load_pct'         => 'integer',
        'optimal_load_pct'     => 'integer',
    ];

    protected $appends = ['cost_per_kwh'];

    // ── Rated cost/kWh at 100 % load (backwards-compatible accessor) ──────────
    public function getCostPerKwhAttribute(): ?float
    {
        $fuelCost = (float) ($this->attributes['fuel_cost_per_liter'] ?? 0);
        $lph      = (float) ($this->attributes['fuel_consumption_lph'] ?? 0);
        $powerKw  = ((float) ($this->attributes['power'] ?? 0)) / 1000.0;

        if ($fuelCost <= 0 || $lph <= 0 || $powerKw <= 0) {
            return null;
        }

        return round(($fuelCost * $lph) / $powerKw, 4);
    }

    // ── Affine fuel model (ISO 8528 / CIBSE) ─────────────────────────────────
    //
    // F(P) = F₀ + (F_rated − F₀) × P / P_rated
    //
    // Where F₀ = no-load fuel consumption (defaults to 30 % of rated if not set).
    // Returns litres consumed per hour at the given actual kW output.
    public function fuelAtLoadKw(float $kW): float
    {
        $ratedKw  = ((float) ($this->attributes['power'] ?? 0)) / 1000.0;
        $fRated   = (float) ($this->attributes['fuel_consumption_lph'] ?? 0);

        if ($ratedKw <= 0 || $fRated <= 0) {
            return 0.0;
        }

        // No-load fuel: use configured value or default to 30 % of rated
        $noLoad = $this->no_load_fuel_lph !== null
            ? (float) $this->no_load_fuel_lph
            : $fRated * 0.30;

        $loadFraction = max(0.0, min(1.0, $kW / $ratedKw));

        return $noLoad + ($fRated - $noLoad) * $loadFraction;
    }

    // Cost per kWh at a given kW output using the affine fuel model
    public function costPerKwhAtLoad(float $kW): float
    {
        if ($kW < 0.001) {
            return 0.0;
        }

        $fuelCost = (float) ($this->attributes['fuel_cost_per_liter'] ?? 0);

        if ($fuelCost <= 0) {
            return 0.0;
        }

        return ($fuelCost * $this->fuelAtLoadKw($kW)) / $kW;
    }

    // Marginal cost: d(fuel_cost)/d(kW) — used in the cost signal
    // = fuel_price × (F_rated - F₀) / P_rated
    public function marginalCostPerKwh(): float
    {
        $ratedKw  = ((float) ($this->attributes['power'] ?? 0)) / 1000.0;
        $fRated   = (float) ($this->attributes['fuel_consumption_lph'] ?? 0);
        $fuelCost = (float) ($this->attributes['fuel_cost_per_liter'] ?? 0);

        if ($ratedKw <= 0 || $fRated <= 0 || $fuelCost <= 0) {
            return 0.0;
        }

        $noLoad = $this->no_load_fuel_lph !== null
            ? (float) $this->no_load_fuel_lph
            : $fRated * 0.30;

        return round($fuelCost * ($fRated - $noLoad) / $ratedKw, 4);
    }

    public function generable()
    {
        return $this->morphTo();
    }
}
