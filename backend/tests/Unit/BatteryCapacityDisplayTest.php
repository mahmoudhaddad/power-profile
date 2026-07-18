<?php

namespace Tests\Unit;

use App\Models\Battery;
use App\Services\BatteryChemistryService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests verifying battery capacity display values match the SLD.
 *
 * Tests 1–4 exercise the arithmetic behind Battery model accessors using pure
 * PHP math — no database connection required.
 *
 * Test 5 verifies BatteryChemistryService presets so the SLD can trust that
 * usable_capacity_kwh is always < nominal_capacity_kwh for every supported chemistry.
 *
 * Covered:
 *   1. nominal_capacity_kwh formula: V × Ah × qty / 1000.
 *   2. age_factor degrades linearly from 1.0, floored at 0.70.
 *   3. usable_capacity_kwh = nominal × DoD × age_factor (verified for new battery).
 *   4. usable < nominal for any DoD < 1.0 and age_factor ≤ 1.0.
 *   5. Every chemistry preset in BatteryChemistryService has DoD < 1.0.
 *   6. solar_system_id is in Battery::$fillable (SLD topology coupling requires it).
 */
class BatteryCapacityDisplayTest extends TestCase
{
    // ── Pure-math helpers (mirror Battery model accessor logic) ──────────────

    private function nominalKwh(float $voltV, float $ah, int $qty): float
    {
        return ($voltV * $ah * $qty) / 1000.0;
    }

    private function ageFactor(float $ageYears, float $degradationPerYear, float $floor = 0.70): float
    {
        return max($floor, 1.0 - $ageYears * $degradationPerYear);
    }

    private function usableKwh(float $nominalKwh, float $dod, float $ageFactor): float
    {
        return $nominalKwh * $dod * $ageFactor;
    }

    // ── Test 1 ────────────────────────────────────────────────────────────────

    /** nominal_capacity_kwh = V × Ah × qty / 1000. */
    public function test_nominal_capacity_kwh_formula(): void
    {
        $this->assertEqualsWithDelta(4.8,   $this->nominalKwh(48.0, 100.0, 1), 0.001);   // 48 V × 100 Ah × 1
        $this->assertEqualsWithDelta(9.6,   $this->nominalKwh(48.0, 100.0, 2), 0.001);   // 48 V × 100 Ah × 2
        $this->assertEqualsWithDelta(5.12,  $this->nominalKwh(51.2, 100.0, 1), 0.001);   // 51.2 V × 100 Ah × 1 (LFP)
        $this->assertEqualsWithDelta(10.24, $this->nominalKwh(51.2, 100.0, 2), 0.001);   // two LFP packs
    }

    // ── Test 2 ────────────────────────────────────────────────────────────────

    /** age_factor degrades linearly from 1.0, floored at 0.70. */
    public function test_age_factor_degradation(): void
    {
        $deg = 0.03;   // generic 3 %/yr

        $this->assertEqualsWithDelta(1.0,  $this->ageFactor(0.0,  $deg), 0.001, 'Brand new');
        $this->assertEqualsWithDelta(0.97, $this->ageFactor(1.0,  $deg), 0.001, '1 yr');
        $this->assertEqualsWithDelta(0.85, $this->ageFactor(5.0,  $deg), 0.001, '5 yr');
        $this->assertEqualsWithDelta(0.70, $this->ageFactor(20.0, $deg), 0.001, 'Floor at 70 %');
    }

    // ── Test 3 ────────────────────────────────────────────────────────────────

    /** usable = nominal × DoD × age_factor for a brand-new battery (age_factor = 1.0). */
    public function test_usable_kwh_for_new_battery(): void
    {
        // Gel lead-acid: 4.8 kWh nominal, 80 % DoD, brand-new
        $nominal = $this->nominalKwh(48.0, 100.0, 1);   // 4.8 kWh
        $usable  = $this->usableKwh($nominal, 0.80, 1.0);
        $this->assertEqualsWithDelta(3.84, $usable, 0.001, 'Gel lead-acid 4.8 kWh × 80 % DoD');

        // LFP: 5.12 kWh nominal, 85 % DoD, brand-new
        $nominal = $this->nominalKwh(51.2, 100.0, 1);   // 5.12 kWh
        $usable  = $this->usableKwh($nominal, 0.85, 1.0);
        $this->assertEqualsWithDelta(4.352, $usable, 0.001, 'LFP 5.12 kWh × 85 % DoD');
    }

    // ── Test 4 ────────────────────────────────────────────────────────────────

    /** usable < nominal whenever DoD < 1.0 and age_factor ≤ 1.0. */
    public function test_usable_always_less_than_nominal_when_dod_below_one(): void
    {
        foreach ([0.50, 0.70, 0.80, 0.85, 0.90] as $dod) {
            $nominal = $this->nominalKwh(48.0, 100.0, 1);
            $usable  = $this->usableKwh($nominal, $dod, 1.0);
            $this->assertLessThan($nominal, $usable,
                "usable must be < nominal for DoD={$dod}");
        }
    }

    // ── Test 5 ────────────────────────────────────────────────────────────────

    /** Every chemistry preset has DoD < 1.0 — the SLD shows usable, never nominal. */
    public function test_every_chemistry_preset_has_dod_below_one(): void
    {
        foreach (BatteryChemistryService::all() as $key => $preset) {
            $this->assertLessThan(
                1.0,
                $preset['depth_of_discharge'],
                "Chemistry '{$key}' has DoD >= 1.0 — SLD would show nominal as usable, which is incorrect"
            );
            $this->assertGreaterThan(
                0.0,
                $preset['depth_of_discharge'],
                "Chemistry '{$key}' has DoD <= 0 — unusable battery"
            );
        }
    }

    // ── Test 6 ────────────────────────────────────────────────────────────────

    /** solar_system_id is in Battery::$fillable — required for SLD hybrid-inverter coupling. */
    public function test_solar_system_id_is_fillable_for_sld_coupling(): void
    {
        $b = new Battery();
        $this->assertContains(
            'solar_system_id',
            $b->getFillable(),
            'solar_system_id must be in $fillable so the SLD can detect solar-coupled batteries'
        );
    }
}
