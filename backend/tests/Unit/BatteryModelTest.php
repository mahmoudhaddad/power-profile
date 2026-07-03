<?php

namespace Tests\Unit;

use App\Models\Battery;
use App\Services\BatteryChemistryService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Battery computed accessors.
 *
 * Uses newFromBuilder() to hydrate models without a live database connection.
 * All tested properties are pure computed accessors — no mutations, no DB writes.
 *
 * Covered:
 *  1. LiFePO4 degradation_per_year is 0.02 (2 %/yr) in BatteryChemistryService.
 *  2. Two identical LFP banks 36 days apart have usable_capacity_kwh within 0.5 %.
 *  3. current_available_kwh = usable_capacity_kwh × current_soc (stored energy identity).
 *  4. Unknown chemistry falls back gracefully — no exception, age_factor within 0–1.
 *  5. Battery at age-floor (very old): age_factor clamps to 0.70.
 */
class BatteryModelTest extends TestCase
{
    // ── helpers ──────────────────────────────────────────────────────────────

    private function bank(array $overrides = []): Battery
    {
        // newFromBuilder() hydrates rawAttributes so getRawOriginal() returns the raw
        // date string needed by getAgeYearsAttribute(). It does not hit the database.
        return (new Battery())->newFromBuilder(array_merge([
            'chemistry'            => 'lithium_lfp',
            'nominal_voltage_v'    => 51.2,
            'capacity_ah_per_unit' => 100.0,
            'quantity'             => 1,
            'series_count'         => 1,
            'parallel_count'       => 1,
            'installation_date'    => date('Y-m-d'), // today = age 0
            'depth_of_discharge'   => 0.90,
            'round_trip_efficiency'=> 0.95,
            'c_rate_charge'        => 0.50,
            'c_rate_discharge'     => 1.00,
            'rated_cycle_life'     => 4000,
            'current_soc'          => 0.50,
            'is_active'            => 1,
        ], $overrides));
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    /** LiFePO4 rate is 2 %/yr — a 36-day battery loses < 0.01 % capacity. */
    public function test_lithium_lfp_degradation_rate_is_two_percent_per_year(): void
    {
        $defaults = BatteryChemistryService::getDefaults('lithium_lfp');
        $this->assertNotNull($defaults, 'lithium_lfp preset must exist');
        $this->assertSame(0.02, $defaults['degradation_per_year'],
            'LFP degradation_per_year must be 0.02 (2 %/yr)');
    }

    /** Two identical LFP banks 36 days apart are within 0.5 % usable capacity. */
    public function test_two_lfp_banks_36_days_apart_have_nearly_identical_usable_capacity(): void
    {
        // r2 installed today; r1 installed 36 days ago (2026-05-28 when today = 2026-07-03)
        $r2 = $this->bank(['installation_date' => date('Y-m-d')]);
        $r1 = $this->bank(['installation_date' => date('Y-m-d', strtotime('-36 days'))]);

        $usableR2 = $r2->usable_capacity_kwh;
        $usableR1 = $r1->usable_capacity_kwh;

        $this->assertGreaterThan(0, $usableR2, 'r2 usable must be positive');
        $this->assertGreaterThan(0, $usableR1, 'r1 usable must be positive');

        $diffPct = abs($usableR1 - $usableR2) / $usableR2 * 100;
        $this->assertLessThan(0.5, $diffPct,
            sprintf(
                'Usable capacity of 36-day-old bank (%.4f kWh) must be within 0.5 %% of '
                . 'brand-new bank (%.4f kWh); got %.4f %%',
                $usableR1, $usableR2, $diffPct
            )
        );
    }

    /** age_factor for a 36-day LFP bank is between 0.99 and 1.00. */
    public function test_36_day_lfp_bank_age_factor_is_near_one(): void
    {
        $r1 = $this->bank(['installation_date' => date('Y-m-d', strtotime('-36 days'))]);

        $this->assertEqualsWithDelta(0.10, $r1->age_years, 0.01,
            '36-day age must be ~0.10 years');
        $this->assertGreaterThanOrEqual(0.99, $r1->age_factor,
            'age_factor for 36-day LFP bank must be >= 0.99');
        $this->assertLessThanOrEqual(1.00, $r1->age_factor,
            'age_factor must not exceed 1.0');
    }

    /**
     * current_available_kwh = usable_capacity_kwh × current_soc.
     * This identity is what the dispatch engine uses as starting charge,
     * and what the panel now displays as "stored kWh".
     */
    public function test_current_available_kwh_equals_usable_times_soc(): void
    {
        foreach ([0.0, 0.25, 0.50, 0.75, 1.0] as $soc) {
            $b = $this->bank(['current_soc' => $soc]);
            $expected = $b->usable_capacity_kwh * $soc;
            $this->assertEqualsWithDelta(
                $expected,
                $b->current_available_kwh,
                1e-9,
                "stored kWh must equal usable × soc at soc={$soc}"
            );
        }
    }

    /** Unknown chemistry key must not throw; falls back to 0.03/yr default. */
    public function test_unknown_chemistry_falls_back_without_exception(): void
    {
        $b = $this->bank(['chemistry' => 'unknown_chemistry_xyz']);

        // Should not throw
        $factor = $b->age_factor;

        $this->assertGreaterThanOrEqual(0.70, $factor,
            'age_factor must be >= 0.70 floor even for unknown chemistry');
        $this->assertLessThanOrEqual(1.0, $factor,
            'age_factor must be <= 1.0');
    }

    /** Very old battery (300 years) hits the 0.70 floor regardless of chemistry. */
    public function test_very_old_battery_age_factor_clamps_to_floor(): void
    {
        // 300 years ago — any chemistry's degradation would drive factor below 0.70
        $old = $this->bank(['installation_date' => date('Y-m-d', strtotime('-109500 days'))]);

        $this->assertSame(0.70, $old->age_factor,
            'age_factor must clamp to 0.70 floor for very old battery');
    }
}
