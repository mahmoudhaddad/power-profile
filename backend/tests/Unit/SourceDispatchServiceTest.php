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
 *  11. Afternoon-ramp suppression: SOC dips from 100 % in post-peak deficit hours (plateau removed).
 *  12. Afternoon battery use reduces generator kWh compared to a battery-disabled baseline.
 *  13. Battery SOC never drops below night reserve floor in any daylight hour.
 *  14. Night generator stays silent when afternoon discharge still leaves enough reserve at sunset.
 *  15. No unmet load is introduced by the afternoon-ramp suppression.
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
     * Generator spare charges battery beyond the post-sunset floor toward higher SOC.
     *
     * Scenario: battery starts depleted; post-sunset load = 4 kWh → target_soc ≈ 52 %.
     * Generator runs at 69 % (≥ 60 %) during 8-hour daylight window, spare ≈ 1.44 kWh/h.
     * Battery charges from 0 → past floor over hours 8-11, then the afternoon-ramp /
     * fraction interplay settles into a charge-drain oscillation.  The final daylight
     * hour (15) is a charge step, so soc_at_sunset ends above battery_target_soc.
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

        // Solar 100 W hours 8-15 — just above daylight threshold (flat → solarPeakH = 8).
        // Post-sunset hours 16-23 × 0.5 kW = 4 kWh → target_kwh ≈ 5.22 → target_soc ≈ 0.52.
        // Daylight load 7 kW → generator ≈ 69 % ≥ 60 %, spare ≈ 1.6 kW AC → 1.44 kWh stored/h.
        // Hours 8-11 charge below floor; hours 12+ enter charge-drain cycle ending on a
        // charge step at hour 15 (sunsetH) → soc_at_sunset ≈ 0.61 > target_soc ≈ 0.52.
        $solar = $this->window(8, 15, 100.0);
        $load  = array_fill(0, 24, 500.0);
        for ($h = 8; $h <= 15; $h++) {
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

    // ── Test 11 ──────────────────────────────────────────────────────────────────

    /**
     * Afternoon-ramp suppression: battery SOC must decrease through post-peak
     * deficit hours — the plateau at ~100 % is removed.
     *
     * Root-cause scenario: solar is constant (solarPeakH = first daylight hour = 7),
     * so every hour 8-17 is "post-peak".  Load (8.5 kW) > solar (0.5 kW) in all
     * those hours, and the generator runs at ~70 % fraction — exactly the condition
     * that used to trigger Step 7 to refill the battery and pin SOC at 100 %.
     *
     * With the fix: Step 7 is suppressed in all afternoon-ramp hours; the battery
     * discharges its above-floor buffer over hours 8-11 and reaches the floor by
     * hour 12.  SOC at hour 12 must be strictly below SOC at hour 7.
     */
    public function test_afternoon_soc_dips_from_plateau_not_pinned_at_100(): void
    {
        $usable = 10.0;
        $batt   = $this->col([$this->battery([
            'current_soc'            => 1.0,   // 10 kWh — fully charged at start
            'usable_capacity_kwh'    => $usable,
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 2.0,   // 2 kW rate cap forces generator to run
            'round_trip_efficiency'  => 0.90,
        ])]);

        // Solar just above daylight threshold all day → solarPeakH = 7 (first hour).
        // Every hour 8-17 is post-peak with load(8500) > solar(500) → afternoon ramp.
        // Generator cap chosen so load fraction ≈ 70 % (was the Step-7 trigger zone).
        $solar = $this->window(7, 17, 500.0);   // 500 W constant (solarPeakH = 7)
        $load  = $this->window(7, 17, 8_500.0); // 8.5 kW constant: load >> solar
        // No night load → battTargetKwh ≈ 1 kWh (reserve margin only).

        $result = $this->svc->dispatch(
            $load,
            $solar,
            0.0,        // no utility
            8_600.0,    // 8.6 kW generator → ~70 % fraction at 6 kW serving load
            $batt,
            0.0
        );

        $soc = $result['battery_soc_trace'];

        // SOC at the end of the afternoon window must be strictly below SOC at its start.
        // Before the fix both would be ~100 % (Step 7 cancelled Step 4 every hour).
        $this->assertLessThan(
            $soc[7],
            $soc[12],
            "SOC at hour 12 ({$soc[12]}) should be below SOC at hour 7 ({$soc[7]}): "
            . 'afternoon plateau must be replaced by a discharge dip'
        );

        // Battery must have actually discharged in the afternoon ramp window.
        $afternoonDischarge = array_sum(array_slice($result['battery_discharged'], 8, 5));
        $this->assertGreaterThan(
            0.0,
            $afternoonDischarge,
            'Battery must discharge in hours 8-12 (post-peak deficit window)'
        );
    }

    // ── Test 12 ──────────────────────────────────────────────────────────────────

    /**
     * Afternoon battery use reduces generator kWh vs. battery-disabled baseline.
     *
     * A 2 kW battery discharge in each deficit hour offsets generator output.
     * Compared to a run where max_discharge = 0 (battery cannot help), generator
     * kWh must be strictly lower when the battery is allowed to discharge.
     */
    public function test_afternoon_battery_discharge_reduces_generator_kwh(): void
    {
        $solar = $this->window(7, 17, 500.0);
        $load  = $this->window(7, 17, 8_500.0);
        $genCap = 8_600.0;

        // Baseline: battery disabled (max_discharge = 0) — generator covers everything.
        $baseline = $this->svc->dispatch(
            $load, $solar, 0.0, $genCap,
            $this->col([$this->battery([
                'current_soc'            => 1.0,
                'usable_capacity_kwh'    => 10.0,
                'max_discharge_power_kw' => 0.0,   // disabled
                'max_charge_power_kw'    => 5.0,
                'round_trip_efficiency'  => 0.90,
            ])]),
            0.0
        );

        // With battery: 2 kW discharge in afternoon ramp displaces generator.
        $withBattery = $this->svc->dispatch(
            $load, $solar, 0.0, $genCap,
            $this->col([$this->battery([
                'current_soc'            => 1.0,
                'usable_capacity_kwh'    => 10.0,
                'max_discharge_power_kw' => 2.0,
                'max_charge_power_kw'    => 5.0,
                'round_trip_efficiency'  => 0.90,
            ])]),
            0.0
        );

        $this->assertLessThan(
            $baseline['stats']['generator_kwh'],
            $withBattery['stats']['generator_kwh'],
            'Generator kWh must be lower when battery discharges in afternoon ramp '
            . "(baseline: {$baseline['stats']['generator_kwh']} kWh, "
            . "with battery: {$withBattery['stats']['generator_kwh']} kWh)"
        );
    }

    // ── Test 13 ──────────────────────────────────────────────────────────────────

    /**
     * Phase 2 regression after afternoon suppression: battery SOC must never drop
     * below the night reserve floor during any daylight hour.
     *
     * The afternoon-ramp suppression removes the gen→battery recharge that was
     * inadvertently keeping SOC above the floor; the Step-4 discharge ceiling must
     * still enforce the floor correctly.
     */
    public function test_afternoon_discharge_never_drops_below_night_floor(): void
    {
        $usable = 10.0;
        $batt   = $this->col([$this->battery([
            'current_soc'            => 1.0,
            'usable_capacity_kwh'    => $usable,
            'max_discharge_power_kw' => 2.0,
            'max_charge_power_kw'    => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        $solar = $this->window(7, 17, 500.0);
        $load  = $this->window(7, 17, 8_500.0);

        $result    = $this->svc->dispatch($load, $solar, 0.0, 8_600.0, $batt, 0.0);
        $targetKwh = $result['stats']['battery_target_kwh'];

        for ($h = 7; $h <= 17; $h++) {
            $currentKwh = $result['battery_soc_trace'][$h] * $usable;
            $this->assertGreaterThanOrEqual(
                $targetKwh - 0.01,
                $currentKwh,
                "Battery dropped below night reserve floor at daylight hour $h "
                . "(current {$currentKwh} kWh < target {$targetKwh} kWh)"
            );
        }
    }

    // ── Test 14 ──────────────────────────────────────────────────────────────────

    /**
     * Night generator stays off after afternoon discharge when enough reserve
     * remains at sunset.
     *
     * The battery discharges its above-floor buffer in the afternoon (displacing
     * generator fuel) then sits at the floor.  The floor was calculated to cover
     * all night load, so the generator must not fire at night.
     *
     * Setup: light night load (0.5 kW × 8 hours = 4 kWh) → battTarget ≈ 5.2 kWh.
     * Generous solar fills the battery to full before the afternoon ramp.
     * Afternoon: 3 kW solar, 5.5 kW load (2.5 kW deficit), battery covers it.
     * By sunset the battery is at or above the 5.2 kWh floor → night is battery-only.
     */
    public function test_night_generator_stays_off_after_afternoon_discharge(): void
    {
        $usable = 10.0;
        $batt   = $this->col([$this->battery([
            'current_soc'            => 1.0,   // fully charged at dawn
            'usable_capacity_kwh'    => $usable,
            'max_discharge_power_kw' => 5.0,
            'max_charge_power_kw'    => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        // Solar: strong morning (peaks at hour 12 at 12 kW, uniquely so solarPeakH=12),
        // declining afternoon.  Night hours: 0-6 and 19-23.
        $solar = array_fill(0, 24, 0.0);
        foreach ([7 => 4_000, 8 => 7_000, 9 => 10_000, 10 => 11_000, 11 => 11_500,
                  12 => 12_000, 13 => 9_000, 14 => 6_000, 15 => 3_000, 16 => 1_000] as $h => $w) {
            $solar[$h] = (float) $w;
        }

        // Night load 0.5 kW, day load 5.5 kW.
        // Daylight = hours 7-16 (matches solar array above).
        // Night energy = (7 pre-dawn + 7 post-dusk) h × 0.5 kW = 7 kWh.
        // battTarget ≈ min(10, 7/0.949 + 1.0) ≈ 8.38 kWh.
        // Above-floor buffer = 10 - 8.38 = 1.62 kWh for afternoon discharge.
        $load = array_fill(0, 24, 500.0);
        for ($h = 7; $h <= 16; $h++) {
            $load[$h] = 5_500.0;
        }

        $result = $this->svc->dispatch(
            $load,
            $solar,
            0.0,        // no utility
            10_000.0,   // 10 kW generator — must stay silent at night
            $batt,
            12_000.0
        );

        $this->assertSame(
            0,
            $result['stats']['night_generator_hours'],
            'Night generator must stay off: afternoon discharge must not deplete below the reserve floor'
        );
        $this->assertSame(
            0.0,
            $result['stats']['night_generator_kwh'],
            'Night generator kWh must be zero after afternoon discharge'
        );
    }

    // ── Test 15 ──────────────────────────────────────────────────────────────────

    /**
     * No unmet load introduced by afternoon-ramp suppression.
     *
     * The generator fills any gap the battery cannot cover (rate-limited or at floor).
     * Suppressing Step 7 only prevents gen→battery recharge; it does not reduce the
     * generator's availability for load serving.  Unmet load must remain zero.
     */
    public function test_no_unmet_load_after_afternoon_ramp_suppression(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'            => 1.0,
            'usable_capacity_kwh'    => 10.0,
            'max_discharge_power_kw' => 2.0,
            'max_charge_power_kw'    => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        $solar = $this->window(7, 17, 500.0);
        $load  = $this->window(7, 17, 8_500.0);

        $result = $this->svc->dispatch($load, $solar, 0.0, 8_600.0, $batt, 0.0);

        $this->assertSame(
            0.0,
            $result['stats']['unmet_kwh'],
            'Unmet load must remain zero: generator covers what battery cannot'
        );
        $this->assertEmpty(
            $result['unmet_hours'],
            'No hours should have unmet load after afternoon-ramp suppression'
        );
    }

    // ── Test 16 ──────────────────────────────────────────────────────────────────

    /**
     * Morning gap (07:00): battery above dynamic floor covers the load-solar gap;
     * generator must not fire.
     *
     * Mirrors the real tooltip: demand 1.8 kW, solar ~906 W at h=7, battery 66 % SOC.
     * Post-sunset load = 6 h × 0.5 kW = 3 kWh → dynamicFloor ≈ 4.16 kWh.
     * Battery at 66 % × 10 kWh = 6.6 kWh → aboveReserve ≈ 2.44 kWh.
     * Step 4 discharges the 894 W gap; generator_used[7] must equal 0.
     */
    public function test_morning_gap_covered_by_battery_not_generator(): void
    {
        $usable = 10.0;
        $batt   = $this->col([$this->battery([
            'current_soc'            => 0.66,  // 6.6 kWh stored
            'usable_capacity_kwh'    => $usable,
            'max_discharge_power_kw' => 5.0,
            'max_charge_power_kw'    => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        // Solar rising to peak at noon; 906 W at h=7.
        $solar = array_fill(0, 24, 0.0);
        foreach ([
            7 => 906,  8 => 2_000, 9 => 4_000, 10 => 6_500,
            11 => 8_000, 12 => 9_000, 13 => 8_000, 14 => 6_000,
            15 => 3_500, 16 => 1_500, 17 => 400,
        ] as $h => $w) {
            $solar[$h] = (float) $w;
        }

        // 1800 W daytime; pre-sunrise load = 0 so battery stays at 6.6 kWh until h=7.
        // Post-sunset 18-23 × 0.5 kW = 3 kWh → dynamicFloor@h=7 ≈ 4.16 kWh < 6.6 kWh.
        $load = array_fill(0, 24, 0.0);
        for ($h = 7; $h <= 17; $h++) {
            $load[$h] = 1_800.0;
        }
        for ($h = 18; $h <= 23; $h++) {
            $load[$h] = 500.0;
        }

        $result = $this->svc->dispatch(
            $load,
            $solar,
            0.0,        // no utility
            10_000.0,   // 10 kW generator available
            $batt,
            0.0
        );

        $genUsed = $result['generator_used'];
        $this->assertSame(
            0.0,
            (float) $genUsed[7],
            'At 07:00 the battery has above-floor energy — generator must not fire to cover the small morning gap'
        );
    }

    // ── Test 17 ──────────────────────────────────────────────────────────────────

    /**
     * Morning pre-peak gate suppresses generator→battery charging when solar is rising
     * and battery is already above the dynamic floor.
     *
     * Setup: battery at 30 % (3 kWh); post-sunset 2 h × 0.5 kW = 1 kWh →
     * dynamicFloor@h=7 ≈ 2.054 kWh → aboveReserve = 0.946 kWh > 0 → inMorningRamp = true.
     * Generator covers the ~7.3 kW load gap (fraction ≥ 60 %) but must NOT
     * use spare capacity to charge the battery while solar is still rising.
     */
    public function test_morning_ramp_gate_suppresses_generator_battery_charging(): void
    {
        $usable = 10.0;
        $batt   = $this->col([$this->battery([
            'current_soc'            => 0.30,  // 3 kWh — above the 2.054 kWh floor
            'usable_capacity_kwh'    => $usable,
            'max_discharge_power_kw' => 0.5,   // low discharge rate
            'max_charge_power_kw'    => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        // Solar rising, peak at h=12.  Only 200 W at h=7 (well below load).
        $solar = array_fill(0, 24, 0.0);
        foreach ([
            7 => 200,  8 => 1_000, 9 => 3_000, 10 => 5_500,
            11 => 7_500, 12 => 9_000, 13 => 8_000, 14 => 5_000,
            15 => 2_000, 16 => 500,
        ] as $h => $w) {
            $solar[$h] = (float) $w;
        }

        // 8 kW daytime load; small night load (2 h post-sunset × 0.5 kW = 1 kWh).
        $load = array_fill(0, 24, 0.0);
        for ($h = 7; $h <= 16; $h++) {
            $load[$h] = 8_000.0;
        }
        $load[17] = 500.0;
        $load[18] = 500.0;

        $result = $this->svc->dispatch(
            $load,
            $solar,
            0.0,
            10_000.0,   // 10 kW generator
            $batt,
            0.0
        );

        $chargedByGen = $result['battery_charged_gen'] ?? array_fill(0, 24, 0.0);
        $this->assertSame(
            0.0,
            (float) $chargedByGen[7],
            'Morning pre-peak gate must suppress gen→battery charging at h=7 when battery is above floor'
        );
    }

    // ── Test 18 ──────────────────────────────────────────────────────────────────

    /**
     * No same-hour round-trip: generator must not charge battery in any hour
     * where the battery also discharged to load.
     *
     * Scenario: modest solar (peak 5 kW), high load (5.5 kW day, 2 kW night),
     * rate-limited discharge (2 kW).  Generator runs at ≥ 60 % for most daylight
     * hours.  The $dischargeW === 0 gate must ensure that whenever the battery
     * contributes discharge, generator spare charging is suppressed for that hour.
     */
    public function test_no_same_hour_round_trip_discharge_and_gen_charge(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'            => 0.50,
            'usable_capacity_kwh'    => 10.0,
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 2.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        $solar = array_fill(0, 24, 0.0);
        foreach ([7=>500, 8=>1500, 9=>3000, 10=>4000, 11=>4500, 12=>5000,
                  13=>4500, 14=>3500, 15=>2000, 16=>800, 17=>200] as $h => $w) {
            $solar[$h] = (float) $w;
        }
        $load = array_fill(0, 24, 2000.0);
        for ($h = 7; $h <= 17; $h++) { $load[$h] = 5500.0; }

        $result = $this->svc->dispatch($load, $solar, 0.0, 8000.0, $batt, 0.0);

        for ($h = 0; $h < 24; $h++) {
            $disch  = (float) ($result['battery_discharged'][$h] ?? 0);
            $chgGen = (float) ($result['battery_charged_gen'][$h] ?? 0);
            if ($disch > 0.0 && $chgGen > 0.0) {
                $this->fail(
                    "Same-hour round-trip at h=$h: battery discharged {$disch} W "
                    . "AND generator charged {$chgGen} W to battery"
                );
            }
        }
        $this->assertTrue(true, 'No same-hour round-trip occurred');
    }

    // ── Test 19 ──────────────────────────────────────────────────────────────────

    /**
     * Generator-sourced battery charging is ~0 when battery discharges cover load.
     *
     * The total energy charged from the generator must be zero (or near-zero) in a
     * scenario where solar surplus is sufficient to build the reserve and battery
     * discharge covers afternoon/night load.  Generator spare should not trigger
     * because the battery is simultaneously serving load.
     */
    public function test_generator_sourced_charging_zero_when_solar_covers_reserve(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'            => 0.0,
            'usable_capacity_kwh'    => 10.0,
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        // Generous solar fills battery from 0 to full by mid-morning.
        // Afternoon discharge covers declining solar + night load.
        $solar = array_fill(0, 24, 0.0);
        foreach ([7=>906, 8=>2000, 9=>4500, 10=>6500, 11=>8200, 12=>9000,
                  13=>8500, 14=>7000, 15=>5000, 16=>3000, 17=>1200] as $h => $w) {
            $solar[$h] = (float) $w;
        }
        $load = array_fill(0, 24, 0.0);
        for ($h = 7;  $h <= 17; $h++) { $load[$h] = 1800.0; }
        for ($h = 18; $h <= 23; $h++) { $load[$h] = 500.0; }

        $result = $this->svc->dispatch($load, $solar, 0.0, 5000.0, $batt, 0.0);

        $this->assertSame(
            0.0,
            $result['stats']['battery_charged_gen_kwh'],
            'Generator must not charge battery when solar surplus covers the reserve and battery discharges to load'
        );
        $this->assertSame(0.0, $result['stats']['unmet_kwh'], 'No unmet load');
    }

    // ── Test 20 ──────────────────────────────────────────────────────────────────

    /**
     * Night generator stays off even when night load is heavy, if battery covers it.
     * No simultaneous battery discharge + generator charging at any night hour.
     */
    public function test_no_gen_battery_charge_during_night_discharge(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'            => 1.0,   // full at sunset
            'usable_capacity_kwh'    => 10.0,
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        // No solar; large night load drains battery; generator covers what battery can't.
        $load = array_fill(0, 24, 3000.0);

        $result = $this->svc->dispatch($load, array_fill(0, 24, 0.0), 0.0, 8000.0, $batt, 0.0);

        for ($h = 0; $h < 24; $h++) {
            $disch  = (float) ($result['battery_discharged'][$h] ?? 0);
            $chgGen = (float) ($result['battery_charged_gen'][$h] ?? 0);
            if ($disch > 0.0 && $chgGen > 0.0) {
                $this->fail(
                    "Night round-trip at h=$h: battery discharged {$disch} W "
                    . "AND generator charged battery {$chgGen} W simultaneously"
                );
            }
        }
        $this->assertSame(0.0, $result['stats']['unmet_kwh'], 'No unmet load');
        $this->assertTrue(true, 'No same-hour night round-trip');
    }

    // ── Test 21 ──────────────────────────────────────────────────────────────────

    /**
     * Case B boost: on a solar-deficit day, generator running below 60 % in daylight
     * charges battery to reach 60 %, banking energy that cuts night generator hours.
     *
     * Scenario: solar peaks at 5 kW but daytime load is 3.5 kW — total daytime surplus
     * (≈ 4.5 kWh) is well below battTargetKwh (10 kWh).  solarCoversTarget = false.
     * Battery starts full; pre-sunrise drain depletes it by h=7.
     * Old dispatch: 11 generator hours.  With Case B boost at h=7–8 (sub-60 % hours),
     * battery is recharged during day → covers 2 extra night hours → ≤ 9 generator hours.
     */
    public function test_case_b_boost_reduces_night_generator_hours_on_solar_deficit_day(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'            => 1.0,
            'usable_capacity_kwh'    => 10.0,
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 2.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        $solar = [0,0,0,0,0,0,0,500,2000,4000,5000,5000,4500,3500,2000,1000,300,0,0,0,0,0,0,0];
        $load  = array_merge(
            array_fill(0, 7, 1500.0),   // pre-sunrise 1.5 kW
            array_fill(0, 10, 3500.0),  // daytime 3.5 kW (hours 7-16)
            array_fill(0, 7, 2000.0)    // post-sunset 2 kW (hours 17-23)
        );

        $result = $this->svc->dispatch($load, $solar, 0.0, 8_000.0, $batt, 0.0);

        $this->assertLessThanOrEqual(
            9,
            $result['stats']['generator_hours'],
            'Case B boost must reduce total generator hours to ≤ 9 '
            . "(got {$result['stats']['generator_hours']})"
        );
        $this->assertSame(0.0, $result['stats']['unmet_kwh'], 'No unmet load');

        // No same-hour round-trip in any hour.
        for ($h = 0; $h < 24; $h++) {
            $disch  = (float) ($result['battery_discharged'][$h] ?? 0);
            $chgGen = (float) ($result['battery_charged_gen'][$h] ?? 0);
            if ($disch > 0.0 && $chgGen > 0.0) {
                $this->fail("Same-hour round-trip at h=$h");
            }
        }
    }

    // ── Test 22 ──────────────────────────────────────────────────────────────────

    /**
     * Case B suppressed on solar-surplus day: when solar surplus covers battTargetKwh,
     * generator sub-60 % hours in daylight must NOT charge the battery ($solarCoversTarget
     * = true blocks Case B).  Generator-sourced charging must remain zero even though the
     * generator fires in the first daylight hour at < 60 %.
     *
     * Scenario: generous solar (peak 9 kW), light 1.8 kW daytime load, short night (3 kWh).
     * battTargetKwh ≈ 4.16 kWh; total daylight surplus ≈ 37.5 kWh >> target.
     * At h=7 generator covers only a 894 W gap (< 60 % of 5 kW rated) — Case B gate
     * (solarCoversTarget = true) must suppress charging.
     */
    public function test_case_b_suppressed_when_solar_surplus_covers_battery_target(): void
    {
        $batt = $this->col([$this->battery([
            'current_soc'            => 0.0,
            'usable_capacity_kwh'    => 10.0,
            'max_charge_power_kw'    => 5.0,
            'max_discharge_power_kw' => 5.0,
            'round_trip_efficiency'  => 0.90,
        ])]);

        $solar = array_fill(0, 24, 0.0);
        foreach ([7 => 906, 8 => 2000, 9 => 4500, 10 => 6500, 11 => 8200,
                  12 => 9000, 13 => 8500, 14 => 7000, 15 => 5000, 16 => 3000, 17 => 1200]
                 as $h => $w) {
            $solar[$h] = (float) $w;
        }
        $load = array_fill(0, 24, 0.0);
        for ($h = 7;  $h <= 17; $h++) { $load[$h] = 1_800.0; }
        for ($h = 18; $h <= 23; $h++) { $load[$h] = 500.0; }

        $result = $this->svc->dispatch($load, $solar, 0.0, 5_000.0, $batt, 0.0);

        $this->assertSame(
            0.0,
            $result['stats']['battery_charged_gen_kwh'],
            'Case B must be suppressed when solar surplus covers the battery target: '
            . 'battery_charged_gen_kwh must be zero'
        );
        $this->assertSame(0.0, $result['stats']['unmet_kwh'], 'No unmet load');
    }

    // ── Test 23 ──────────────────────────────────────────────────────────────────

    /**
     * Lead-acid 4.8 kWh nominal → ~2.4 kWh usable (DoD 0.50).
     * Lithium LFP 4.8 kWh nominal → ~4.08 kWh usable (DoD 0.85).
     *
     * The dispatch engine receives usable_capacity_kwh directly; this test
     * verifies the expected values hold before touching the dispatch layer.
     */
    public function test_lead_acid_usable_half_nominal_lithium_lfp_usable_85pct(): void
    {
        $nominalKwh = 4.8;

        $laUsable  = $nominalKwh * 0.50; // 2.4 kWh
        $lfpUsable = $nominalKwh * 0.85; // 4.08 kWh

        $this->assertEqualsWithDelta(2.4, $laUsable,  0.001, 'Lead-acid usable should be 2.4 kWh');
        $this->assertEqualsWithDelta(4.08, $lfpUsable, 0.001, 'LFP usable should be ~4.08 kWh');
        $this->assertGreaterThan($laUsable, $lfpUsable, 'LFP delivers more usable energy than lead-acid for same nominal');
    }

    // ── Test 24 ──────────────────────────────────────────────────────────────────

    /**
     * For the same building demand, lead-acid RTE (0.82) causes the battery to
     * deliver less real energy to the load per kWh charged than LFP RTE (0.92).
     *
     * Concretely: if both batteries start at 50 % SOC and an identical deficit
     * exists, LFP discharges more kWh to the load because the one-way efficiency
     * (sqrt of RTE) is higher.
     *
     * Because the dispatch engine caps discharge by usable × soc (energy ceiling)
     * and by max_discharge_power_kw (rate ceiling), we set a scenario where the
     * LFP battery can actually deliver MORE to the load because:
     *   (a) it has more usable energy (DoD 85 % vs 50 %), and
     *   (b) it discharges faster (C-rate 1.0 vs 0.2).
     * The result: LFP covers more load → generator runs fewer kWh.
     */
    public function test_lfp_delivers_more_to_load_than_lead_acid_same_nominal(): void
    {
        $nominalKwh = 10.0;
        $soc        = 0.5;

        // Lead-acid: DoD 0.50, RTE 0.82, C/5 discharge
        $leadAcid = $this->col([$this->battery([
            'current_soc'            => $soc,
            'usable_capacity_kwh'    => $nominalKwh * 0.50,  // 5.0 kWh
            'max_discharge_power_kw' => $nominalKwh * 0.20,  // 2.0 kW
            'max_charge_power_kw'    => $nominalKwh * 0.10,  // 1.0 kW
            'round_trip_efficiency'  => 0.82,
        ])]);

        // Lithium LFP: DoD 0.85, RTE 0.92, C-rate 1.0 discharge
        $lithiumLfp = $this->col([$this->battery([
            'current_soc'            => $soc,
            'usable_capacity_kwh'    => $nominalKwh * 0.85,  // 8.5 kWh
            'max_discharge_power_kw' => $nominalKwh * 1.00,  // 10.0 kW
            'max_charge_power_kw'    => $nominalKwh * 0.50,  // 5.0 kW
            'round_trip_efficiency'  => 0.92,
        ])]);

        // Night-heavy scenario: generator must cover overnight load.
        // Both are solar-coupled; solar is zero (night scenario only).
        $load  = array_fill(0, 24, 2000.0); // 2 kW constant
        $solar = array_fill(0, 24, 0.0);

        $resLa  = $this->svc->dispatch($load, $solar, 0.0, 10_000.0, $leadAcid,  0.0);
        $resLfp = $this->svc->dispatch($load, $solar, 0.0, 10_000.0, $lithiumLfp, 0.0);

        $laDischarge  = $resLa['stats']['battery_discharged_kwh']  ?? 0.0;
        $lfpDischarge = $resLfp['stats']['battery_discharged_kwh'] ?? 0.0;

        $this->assertGreaterThan(
            $laDischarge,
            $lfpDischarge,
            "LFP ({$lfpDischarge} kWh discharged) must deliver more to load than lead-acid ({$laDischarge} kWh) "
            . 'for same nominal capacity and same SOC — higher DoD and faster C-rate'
        );

        $this->assertSame(0.0, $resLa['stats']['unmet_kwh'],  'Lead-acid: no unmet load');
        $this->assertSame(0.0, $resLfp['stats']['unmet_kwh'], 'LFP: no unmet load');
    }

    // ── Test 25 ──────────────────────────────────────────────────────────────────

    /**
     * Same building, same nominal battery capacity: lead-acid uses MORE generator
     * kWh than LFP because it stores less usable energy (DoD 50 % vs 85 %) and
     * has a lower RTE, so the battery covers less night load.
     *
     * This validates Phase 3: chemistry flows through naturally — no separate
     * generator hack, just usable_capacity_kwh and round_trip_efficiency doing work.
     */
    public function test_lead_acid_causes_higher_generator_kwh_than_lfp_same_nominal(): void
    {
        $nominalKwh = 10.0;

        $leadAcid = $this->col([$this->battery([
            'current_soc'            => 1.0,
            'usable_capacity_kwh'    => $nominalKwh * 0.50,  // 5.0 kWh
            'max_discharge_power_kw' => $nominalKwh * 0.20,  // 2.0 kW
            'max_charge_power_kw'    => $nominalKwh * 0.10,  // 1.0 kW
            'round_trip_efficiency'  => 0.82,
        ])]);

        $lithiumLfp = $this->col([$this->battery([
            'current_soc'            => 1.0,
            'usable_capacity_kwh'    => $nominalKwh * 0.85,  // 8.5 kWh
            'max_discharge_power_kw' => $nominalKwh * 1.00,  // 10.0 kW
            'max_charge_power_kw'    => $nominalKwh * 0.50,  // 5.0 kW
            'round_trip_efficiency'  => 0.92,
        ])]);

        // Day+night load; modest solar in daylight.
        $solar = array_fill(0, 24, 0.0);
        foreach ([7 => 1000, 8 => 2500, 9 => 4000, 10 => 5000,
                  11 => 5500, 12 => 5000, 13 => 4000, 14 => 2500,
                  15 => 1500, 16 => 800, 17 => 200] as $h => $w) {
            $solar[$h] = (float) $w;
        }
        $load = array_fill(0, 24, 1500.0);
        for ($h = 7; $h <= 17; $h++) { $load[$h] = 3000.0; }

        $resLa  = $this->svc->dispatch($load, $solar, 0.0, 8_000.0, $leadAcid,  6_000.0);
        $resLfp = $this->svc->dispatch($load, $solar, 0.0, 8_000.0, $lithiumLfp, 6_000.0);

        $this->assertGreaterThan(
            $resLfp['stats']['generator_kwh'],
            $resLa['stats']['generator_kwh'],
            'Lead-acid must cause higher generator kWh than LFP for same nominal capacity: '
            . "lead-acid {$resLa['stats']['generator_kwh']} kWh vs LFP {$resLfp['stats']['generator_kwh']} kWh"
        );

        $this->assertSame(0.0, $resLa['stats']['unmet_kwh'],  'Lead-acid: no unmet load');
        $this->assertSame(0.0, $resLfp['stats']['unmet_kwh'], 'LFP: no unmet load');
    }

    // ── Test 26 ──────────────────────────────────────────────────────────────────

    /**
     * stored = usable × current_soc (always consistent).
     * Changing chemistry changes usable AND battery load coverage;
     * building demand is identical regardless of chemistry.
     */
    public function test_building_demand_identical_regardless_of_chemistry(): void
    {
        $nominalKwh = 10.0;
        $soc        = 0.7;

        $leadAcid = $this->col([$this->battery([
            'current_soc'            => $soc,
            'usable_capacity_kwh'    => $nominalKwh * 0.50,
            'max_discharge_power_kw' => $nominalKwh * 0.20,
            'max_charge_power_kw'    => $nominalKwh * 0.10,
            'round_trip_efficiency'  => 0.82,
        ])]);

        $lithiumLfp = $this->col([$this->battery([
            'current_soc'            => $soc,
            'usable_capacity_kwh'    => $nominalKwh * 0.85,
            'max_discharge_power_kw' => $nominalKwh * 1.00,
            'max_charge_power_kw'    => $nominalKwh * 0.50,
            'round_trip_efficiency'  => 0.92,
        ])]);

        $solar = array_fill(0, 24, 0.0);
        foreach ([8 => 2000, 9 => 4000, 10 => 5000, 11 => 5000,
                  12 => 4500, 13 => 3000, 14 => 1500] as $h => $w) {
            $solar[$h] = (float) $w;
        }
        $load = array_fill(0, 24, 1000.0);
        for ($h = 8; $h <= 14; $h++) { $load[$h] = 3500.0; }

        $resLa  = $this->svc->dispatch($load, $solar, 5_000.0, 0.0, $leadAcid,  5_500.0);
        $resLfp = $this->svc->dispatch($load, $solar, 5_000.0, 0.0, $lithiumLfp, 5_500.0);

        // Total load served must be identical for both chemistries
        $laTotalLoad  = $resLa['stats']['total_load_kwh'];
        $lfpTotalLoad = $resLfp['stats']['total_load_kwh'];
        $this->assertEqualsWithDelta(
            $laTotalLoad,
            $lfpTotalLoad,
            0.01,
            'Building demand must be identical regardless of battery chemistry: '
            . "lead-acid {$laTotalLoad} kWh vs LFP {$lfpTotalLoad} kWh"
        );

        $this->assertSame(0.0, $resLa['stats']['unmet_kwh'],  'Lead-acid: no unmet load');
        $this->assertSame(0.0, $resLfp['stats']['unmet_kwh'], 'LFP: no unmet load');

        // stored = usable × soc (trivially verify the arithmetic is consistent)
        $laStoredKwh  = $nominalKwh * 0.50 * $soc;
        $lfpStoredKwh = $nominalKwh * 0.85 * $soc;
        $this->assertEqualsWithDelta(3.5,  $laStoredKwh,  0.001, 'Lead-acid stored = 10 × 0.50 × 0.7 = 3.5 kWh');
        $this->assertEqualsWithDelta(5.95, $lfpStoredKwh, 0.001, 'LFP stored = 10 × 0.85 × 0.7 = 5.95 kWh');
    }
}
