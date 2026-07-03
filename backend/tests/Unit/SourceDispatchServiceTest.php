<?php

namespace Tests\Unit;

use App\Services\SourceDispatchService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SourceDispatchService — target-SOC look-ahead dispatch engine.
 *
 * Tests are intentionally free of database access; batteries are stdClass objects
 * wrapped in Illuminate\Database\Eloquent\Collection, which the service iterates
 * without requiring Eloquent model resolution.
 *
 * Covered:
 *   1. Generator never starts when utility alone covers load.
 *   2. Generator stays off in hours where solar fully covers load.
 *   3a. Gen→battery charging ALLOWED when load fraction ≥ 60 % of rated.
 *   3b. Gen→battery charging BLOCKED when load fraction < 60 % of rated.
 *   4. Generator kWh equals load-serving only when guard blocks charging overhead.
 *   5. generator_daylight_hours flag set when generator runs during solar-present hour.
 *   6. Battery NOT discharged during daylight when it is below the night reserve target.
 *   7. Night generator stays off when battery target covers all post-sunset load.
 *   8. Generator spare charges battery BEYOND night target to near-full SOC (Phase 1 regression).
 *   9. Battery above the night floor discharges to shave daytime peak before generator fires.
 *  10. Daytime discharge ceiling (efficiency-corrected) keeps battery exactly at the floor.
 */
class SourceDispatchServiceTest extends TestCase
{
    private SourceDispatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SourceDispatchService();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    /**
     * Build a fake battery stdClass with all properties the dispatch engine reads.
     */
    private function battery(array $overrides = []): object
    {
        return (object) array_merge([
            'id'                     => 1,
            'is_active'              => true,
            'usable_capacity_kwh'    => 10.0,
            'current_soc'            => 0.5,   // 50 % → 5 kWh stored
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 5.0,
            'round_trip_efficiency'  => 0.90,
            'solar_system_id'        => null,
        ], $overrides);
    }

    /** Wrap battery objects in the Eloquent Collection the service type-hints. */
    private function col(array $items): Collection
    {
        return new Collection($items);
    }

    /** Flat 24-element profile at a constant watt value. */
    private function flat(float $w): array
    {
        return array_fill(0, 24, $w);
    }

    /**
     * 24-element profile: $w only during hours $from..$to inclusive, 0 elsewhere.
     */
    private function window(int $from, int $to, float $w): array
    {
        $a = array_fill(0, 24, 0.0);
        for ($h = $from; $h <= $to; $h++) {
            $a[$h] = $w;
        }
        return $a;
    }

    // ── Test 1 ───────────────────────────────────────────────────────────────────

    /**
     * When utility capacity covers all load, the generator must never start.
     */
    public function test_generator_stays_off_when_utility_covers_all_load(): void
    {
        $batt = $this->col([$this->battery(['current_soc' => 0.1])]);

        $result = $this->svc->dispatch(
            $this->flat(1_000.0),  // 1 kW load all day
            $this->flat(0.0),      // no solar
            10_000.0,              // 10 kW utility — fully covers load
            10_000.0,              // 10 kW generator — available but must not start
            $batt,
            0.0
        );

        $this->assertSame(
            array_fill(0, 24, 0.0),
            $result['generator_used'],
            'Generator must not run when utility alone covers all load'
        );
        $this->assertSame(
            array_fill(0, 24, 0.0),
            $result['battery_charged_gen'],
            'battery_charged_gen must be zero when generator never started'
        );
    }

    // ── Test 2 ───────────────────────────────────────────────────────────────────

    /**
     * During every hour where solar output ≥ load, generator must stay off.
     */
    public function test_generator_off_during_hours_solar_fully_covers_load(): void
    {
        // Hours 8–16: solar 6 kW > load 4 kW. All other hours: load = 0.
        $batt = $this->col([$this->battery(['current_soc' => 0.0])]);

        $result = $this->svc->dispatch(
            $this->window(8, 16, 4_000.0),
            $this->window(8, 16, 6_000.0),
            0.0,
            10_000.0,
            $batt,
            6_000.0
        );

        $this->assertSame(
            array_fill(0, 24, 0.0),
            $result['generator_used'],
            'Generator must not run in any hour when solar output ≥ load'
        );
        $this->assertEmpty(
            $result['stats']['generator_daylight_hours'],
            'No daylight-dispatch flags when solar fully covers load'
        );
    }

    // ── Test 3a ──────────────────────────────────────────────────────────────────

    /**
     * Spare generator capacity flows to the battery when load fraction ≥ 60 %.
     *
     * Setup: load = 7 kW, generator cap = 10 kW → fraction = 70 % ≥ 60 %.
     * Battery fully depleted so it has maximum headroom and will accept charge if
     * Step 7 is allowed to fire.
     */
    public function test_gen_charges_battery_when_load_fraction_at_or_above_60_pct(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'         => 0.0,  // depleted → no discharge, full headroom
            'max_charge_power_kw' => 5.0,
        ])]);

        $result = $this->svc->dispatch(
            $this->flat(7_000.0),  // 7 kW → 70 % of 10 kW rated
            $this->flat(0.0),
            0.0,
            10_000.0,
            $batt,
            0.0
        );

        $this->assertGreaterThan(
            0.0,
            array_sum($result['battery_charged_gen']),
            'Generator spare must charge battery when load fraction ≥ 60 % of rated'
        );
    }

    // ── Test 3b ──────────────────────────────────────────────────────────────────

    /**
     * Generator charging is blocked when load fraction < 60 %.
     * The SFC penalty at low load outweighs the value of stored energy.
     */
    public function test_gen_does_not_charge_battery_when_load_fraction_below_60_pct(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'         => 0.0,
            'max_charge_power_kw' => 5.0,
        ])]);

        $result = $this->svc->dispatch(
            $this->flat(2_000.0),  // 2 kW → 20 % of 10 kW rated
            $this->flat(0.0),
            0.0,
            10_000.0,
            $batt,
            0.0
        );

        $this->assertSame(
            array_fill(0, 24, 0.0),
            $result['battery_charged_gen'],
            'Generator must not charge battery when running below 60 % of rated capacity'
        );
    }

    // ── Test 4 ───────────────────────────────────────────────────────────────────

    /**
     * When the guard blocks charging, total generator kWh equals load-serving only.
     */
    public function test_generator_kwh_excludes_charging_overhead_when_guard_fires(): void
    {
        $batt = $this->col([$this->battery(['current_soc' => 0.0])]);

        $result = $this->svc->dispatch(
            $this->flat(2_000.0),
            $this->flat(0.0),
            0.0,
            10_000.0,
            $batt,
            0.0
        );

        $this->assertSame(
            array_fill(0, 24, 0.0),
            $result['battery_charged_gen'],
            'Charging overhead array must be zero when guard blocks Step 7'
        );
        $this->assertEqualsWithDelta(
            48.0,
            $result['stats']['generator_kwh'],
            0.5,
            'Generator kWh should equal load only — no charging overhead below threshold'
        );
    }

    // ── Test 5 ───────────────────────────────────────────────────────────────────

    /**
     * generator_daylight_hours records every hour where generator ran despite solar.
     */
    public function test_generator_daylight_hours_flag_is_set_in_stats(): void
    {
        // Hour 10: solar = 1 kW, load = 8 kW, battery depleted, no utility.
        $batt = $this->col([$this->battery(['current_soc' => 0.0])]);

        $load  = array_fill(0, 24, 0.0);
        $solar = array_fill(0, 24, 0.0);
        $load[10]  = 8_000.0;
        $solar[10] = 1_000.0;

        $result = $this->svc->dispatch(
            $load,
            $solar,
            0.0,
            10_000.0,
            $batt,
            1_000.0
        );

        $this->assertContains(
            10,
            $result['stats']['generator_daylight_hours'],
            'Hour 10 must appear in generator_daylight_hours: generator ran while solar was present'
        );
    }

    // ── Test 6 ───────────────────────────────────────────────────────────────────

    /**
     * Look-ahead reserve gate: battery must NOT discharge during daylight when its
     * stored energy is below the computed night target.
     *
     * Scenario: night load is large enough that target_kwh = full capacity (10 kWh).
     * Pre-sunrise discharge depletes the battery by hour 6.  During daylight hours 6-18
     * the battery is at 0 kWh < target 10 kWh — the daytime gate must block discharge
     * entirely; utility fills the 2 kW daytime gap instead.
     */
    public function test_battery_not_discharged_during_day_when_below_night_target(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'            => 0.5,   // 5 kWh stored in a 10 kWh bank
            'max_discharge_power_kw' => 5.0,
        ])]);

        // Solar only from hour 6 → hours 0-5 are pre-sunrise night.
        // Night hours (0-5 + 19-23) × 2 kW = 22 kWh  → target caps at 10 kWh.
        $solar = $this->window(6, 18, 3_000.0);
        $load  = array_fill(0, 24, 2_000.0);
        for ($h = 6; $h <= 18; $h++) {
            $load[$h] = 5_000.0;  // 5 kW daytime load, solar only 3 kW → 2 kW gap
        }

        $result = $this->svc->dispatch(
            $load,
            $solar,
            10_000.0,   // utility covers daylight gap
            0.0,        // no generator
            $batt,
            3_000.0
        );

        // Pre-sunrise hours drain the battery to zero by hour 2; daylight guard then
        // blocks discharge in hours 6-18 (battery below target of 10 kWh).
        for ($h = 6; $h <= 18; $h++) {
            $this->assertSame(
                0.0,
                $result['battery_discharged'][$h],
                "battery_discharged must be 0 in daylight hour $h (battery below night target)"
            );
        }
    }

    // ── Test 7 ───────────────────────────────────────────────────────────────────

    /**
     * Look-ahead night dispatch: generator must stay silent at night when the battery
     * can cover all post-sunset load.
     *
     * Scenario: battery starts full (10 kWh).  Solar recharges it after pre-sunrise
     * drain.  Night load is light enough that the battery delivers it all → generator
     * must record zero night hours and zero night kWh.
     */
    public function test_night_generator_off_when_battery_covers_night_load(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'            => 1.0,   // 10 kWh — fully charged
            'usable_capacity_kwh'    => 10.0,
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        // Solar 8 kW hours 7-18; constant 0.5 kW load all 24 h.
        // Night energy = (7 + 6) h × 0.5 kW = 6.5 kWh.
        // target_kwh ≈ 6.5/0.949 + 1.0 ≈ 7.85 kWh — well within the 10 kWh bank.
        // Battery starts full, solar recharges any drain → battery covers all night.
        $result = $this->svc->dispatch(
            $this->flat(500.0),              // 0.5 kW constant load
            $this->window(7, 18, 8_000.0),  // 8 kW solar hours 7-18
            0.0,                             // no utility
            10_000.0,                        // 10 kW generator — available but must be silent at night
            $batt,
            8_000.0
        );

        $this->assertSame(
            0,
            $result['stats']['night_generator_hours'],
            'Generator must not run at night when battery target covers all night load'
        );
        $this->assertSame(
            0.0,
            $result['stats']['night_generator_kwh'],
            'Night generator kWh must be zero when battery covers all night load'
        );
    }

    // ── Test 8 ───────────────────────────────────────────────────────────────────

    /**
     * Phase 1 regression: generator spare charges battery all the way to near-full SOC,
     * not just up to the night-reserve target.
     *
     * Scenario: battery starts depleted, night target ≈ 89 % of usable.  Generator
     * runs at 69 % (≥ 60 %) during the 9-hour daylight window, giving 1.6 kW spare.
     * With the old battNeedsCharge cap the battery would stop at target SOC and
     * soc_at_sunset would equal battery_target_soc.  With the new code the generator
     * keeps filling until the headroom hits zero — soc_at_sunset must be strictly
     * greater than battery_target_soc (by at least the spare capacity of one hour).
     */
    public function test_generator_spare_charges_battery_beyond_night_target_to_near_full(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'            => 0.0,   // starts depleted
            'usable_capacity_kwh'    => 10.0,
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        // Solar 100 W during hours 8-16 — just above the 50 W daylight threshold.
        // Night load 0.5 kW × 15 night hours = 7.5 kWh.
        // target_kwh = min(10, 7.5/0.949 + 1.0) ≈ 8.9 kWh → target_soc ≈ 0.89.
        // Daylight load 7 kW → generator = 6.9 kW = 69 % ≥ 60 %, spare = 1.6 kW.
        // Nine daylight hours × 1.44 kWh charging/h fills the battery past 8.9 kWh.
        $solar = $this->window(8, 16, 100.0);
        $load  = array_fill(0, 24, 500.0);
        for ($h = 8; $h <= 16; $h++) {
            $load[$h] = 7_000.0;
        }

        $result = $this->svc->dispatch(
            $load,
            $solar,
            0.0,         // no utility
            10_000.0,    // 10 kW generator
            $batt,
            0.0
        );

        $targetSoc = $result['stats']['battery_target_soc'];
        $sunsetSoc = $result['stats']['soc_at_sunset'];

        $this->assertGreaterThan(
            $targetSoc,
            $sunsetSoc,
            "soc_at_sunset ($sunsetSoc) must exceed battery_target_soc ($targetSoc): "
            . 'generator must fill the battery past the night floor toward 100 % usable'
        );
    }

    // ── Test 9 ───────────────────────────────────────────────────────────────────

    /**
     * Phase 2: when the battery holds energy above the night floor, it discharges
     * first to cover the daytime load gap — the generator stays off for that hour.
     *
     * Scenario: battery starts full (100 % SOC), night floor is the 10 % reserve
     * only (no night load), so 90 % of usable capacity is available for daytime
     * peak shaving.  Solar 3 kW, load 4 kW — gap is 1 kW.  Battery covers the gap
     * entirely in the first daylight hour; generator is not needed.
     */
    public function test_battery_above_target_covers_daytime_gap_before_generator(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'            => 1.0,   // fully charged
            'usable_capacity_kwh'    => 10.0,
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        // Solar 3 kW hours 7-17; load 4 kW during the same window (1 kW gap).
        // No night load → target_kwh = reserve only ≈ 1 kWh → 9 kWh above floor.
        // Battery can discharge 1 kW to cover the gap without touching the generator.
        $solar = $this->window(7, 17, 3_000.0);
        $load  = $this->window(7, 17, 4_000.0);

        $result = $this->svc->dispatch(
            $load,
            $solar,
            0.0,        // no utility
            10_000.0,   // 10 kW generator — must not run in hour 7
            $batt,
            3_000.0
        );

        $this->assertSame(
            0.0,
            $result['generator_used'][7],
            'Generator must stay off in hour 7: battery above target must cover the 1 kW gap first'
        );
    }

    // ── Test 10 ──────────────────────────────────────────────────────────────────

    /**
     * Discharge ceiling (efficiency-corrected): battery SOC must not drop below the
     * night reserve floor during any daylight hour.
     *
     * The ceiling is now `aboveReserveKwh × avgDischEff × 1000` W so that the kWh
     * drained from the battery equals exactly aboveReserveKwh — no overshoot.
     *
     * Scenario: battery starts at 90 % SOC, pre-sunrise drain brings it to
     * ~6.9 kWh.  Night target ≈ 3.1 kWh.  First daylight hour discharges
     * exactly (6.9 − 3.1) kWh worth of usable energy (efficiency-corrected);
     * subsequent hours the battery sits at the floor with zero discharge.
     * All soc_trace values during daylight must be ≥ battery_target_soc.
     */
    public function test_daytime_discharge_ceiling_keeps_soc_at_or_above_night_floor(): void
    {
        $usable = 10.0;
        $batt   = $this->col([$this->battery([
            'current_soc'            => 0.9,   // 9 kWh stored
            'usable_capacity_kwh'    => $usable,
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        // Solar 3 kW hours 4-18 (daylight). Night load 0.5 kW hours 0-3 only.
        // target_kwh = min(10, (4×0.5)/0.949 + 1.0) ≈ 3.108 kWh.
        // Pre-sunrise discharge (hours 0-3) drains battery to ≈ 6.9 kWh (above target).
        // First daylight hour discharges exactly (6.9 − 3.108) kWh and lands at target.
        // All subsequent daylight hours: battery at target, aboveReserve = 0, no discharge.
        $solar = $this->window(4, 18, 3_000.0);
        $load  = array_fill(0, 24, 0.0);
        for ($h = 0; $h <= 3; $h++) {
            $load[$h] = 500.0;   // night load before sunrise
        }
        for ($h = 4; $h <= 18; $h++) {
            $load[$h] = 8_000.0; // daylight load creates gap (solar 3 kW, load 8 kW)
        }

        $result = $this->svc->dispatch(
            $load,
            $solar,
            10_000.0,  // utility covers daylight gap so no generator needed
            0.0,
            $batt,
            3_000.0
        );

        $targetKwh  = $result['stats']['battery_target_kwh'];
        $targetSoc  = $result['stats']['battery_target_soc'];

        // Every daylight hour: battery current ≥ night floor (within float epsilon).
        for ($h = 4; $h <= 18; $h++) {
            $currentKwh = $result['battery_soc_trace'][$h] * $usable;
            $this->assertGreaterThanOrEqual(
                $targetKwh - 0.005,   // 5 Wh float tolerance
                $currentKwh,
                "Battery dipped below night reserve floor at daylight hour $h "
                . "(current {$currentKwh} kWh < target {$targetKwh} kWh)"
            );
        }
    }
}
