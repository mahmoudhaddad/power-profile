<?php

namespace Tests\Unit;

use App\Services\SourceDispatchService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Step 7 generator-efficiency boost tests (Changes 1 & 2 of the efficiency fix).
 *
 * Change 1 — Case C (night boost):
 *   When the generator is running at night below 60 % rated load and the battery
 *   has headroom, the generator is boosted to exactly 60 % by charging the battery.
 *   Common outer guards (genUsed > 0, dischargeW === 0.0, ramp gates, totalHead)
 *   remain unchanged and are exercised explicitly below.
 *
 * Change 2 — per-hour solarStillCoversTarget (Case B gate):
 *   The old single pre-dispatch $solarCoversTarget flag is replaced by a per-hour
 *   evaluation: remaining solar surplus from h+1 to sunset vs. remaining battery
 *   gap (battTargetKwh − current SOC).  Solar-rich early-morning hours stay
 *   suppressed; midday hours where remaining solar genuinely cannot fill the target
 *   now correctly fire the boost.
 *
 * Tests 9–14 follow the numbering of the existing invariant/floor test suites.
 */
class GenEfficientBoostTest extends TestCase
{
    private SourceDispatchService $dispatch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatch = new SourceDispatchService();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Build a minimal battery stub accepted by dispatchOptimized. */
    private function battery(
        float $usableKwh,
        float $socFraction,
        float $chargeKw    = 10.0,
        float $dischargeKw = 5.0,
        float $eff         = 0.93
    ): Collection {
        $b                         = new \stdClass();
        $b->id                     = 1;
        $b->is_active              = true;
        $b->usable_capacity_kwh    = $usableKwh;
        $b->current_soc            = $socFraction;
        $b->max_charge_power_kw    = $chargeKw;
        $b->max_discharge_power_kw = $dischargeKw;
        $b->round_trip_efficiency  = $eff;
        $b->solar_system_id        = null;
        return new Collection([$b]);
    }

    private function go(
        array      $loadW,
        float      $genCapW,
        Collection $batteries,
        ?array     $solarW   = null,
        float      $utilCapW = 0.0
    ): array {
        return $this->dispatch->dispatch(
            $loadW,
            $solarW ?? array_fill(0, 24, 0.0),
            $utilCapW,
            $genCapW,
            $batteries,
            0.0, null,
            0.30   // genMinLoadFloorPct — unchanged from Phase 1
        );
    }

    // ── Test 9 — Case C fires at night ─────────────────────────────────────────

    /**
     * 9.  Generator runs at night at 21 % (3 kW on a 14 kW gen) — well below the
     *     60 % efficient threshold.  Battery is depleted (SOC = 0 %) so Step 4
     *     cannot discharge ($dischargeW = 0), and the battery has 10 kWh headroom
     *     for charging.  Case C must fire and boost generator output to 60 %.
     *
     *     All solar = 0 → every hour is treated as night.
     */
    public function test_case_c_fires_at_night_with_depleted_battery(): void
    {
        $batteries = $this->battery(usableKwh: 10.0, socFraction: 0.0, chargeKw: 10.0);

        $loadW     = array_fill(0, 24, 0.0);
        $loadW[1]  = 3000.0; // 3 kW night load at h=1; 14 kW gen → 21.4 %

        $result = $this->go($loadW, 14000.0, $batteries);

        // Generator must boost to exactly 60 % (8.4 kW) — battery absorbs the extra 5.4 kW
        $this->assertGreaterThanOrEqual(
            8400.0 - 1.0, // 1 W floating-point tolerance
            $result['generator_used'][1],
            'Case C must boost generator from 3 kW to ~60 % (8.4 kW) when battery has headroom at night'
        );

        // Battery must have been charged this hour via the generator spare
        $this->assertGreaterThan(
            0.0,
            $result['battery_charged_gen'][1],
            'Case C must route the generator spare to battery charging'
        );

        // No unmet demand
        $this->assertEqualsWithDelta(0.0, $result['unmet'][1], 1.0,
            'Load must be fully served when generator boosts via Case C');
    }

    // ── Test 10 — Case C does NOT fire when battery covers the load ────────────

    /**
     * 10.  Battery is full (100 % SOC).  At h=1 (night), Step 4 discharges 3 kW
     *      to serve the load — generator never starts.  Case C cannot fire because
     *      the outer guard $genUsed[$h] > 0 is false.
     */
    public function test_case_c_does_not_fire_when_battery_covers_night_load(): void
    {
        $batteries = $this->battery(usableKwh: 10.0, socFraction: 1.0);

        $loadW    = array_fill(0, 24, 0.0);
        $loadW[1] = 3000.0;

        $result = $this->go($loadW, 14000.0, $batteries);

        $this->assertSame(0.0, $result['generator_used'][1],
            'Generator must stay off when battery can serve the load (Case C outer guard: genUsed > 0)');

        $this->assertSame(0.0, $result['battery_charged_gen'][1],
            'No gen→battery charging when generator is off');

        $this->assertEqualsWithDelta(0.0, $result['unmet'][1], 1.0,
            'Battery must fully serve the 3 kW load at h=1');
    }

    // ── Test 11 — Case C blocked when battery discharges in same hour ──────────

    /**
     * 11.  Battery at 50 % SOC discharges at its max rate (5 kW) in Step 4 to
     *      cover part of a large load.  Generator covers the remaining 3 kW.
     *      Because $dischargeW > 0, the outer Step 7 guard blocks Case C —
     *      no same-hour round-trip.
     */
    public function test_case_c_blocked_when_battery_is_discharging_same_hour(): void
    {
        // dischargeKw=5 → battery supplies up to 5000 W in Step 4
        $batteries = $this->battery(usableKwh: 10.0, socFraction: 0.5, chargeKw: 10.0, dischargeKw: 5.0);

        $loadW    = array_fill(0, 24, 0.0);
        $loadW[1] = 8000.0; // 8 kW; battery covers 5 kW, gen covers 3 kW

        $result = $this->go($loadW, 14000.0, $batteries);

        // Generator runs only to cover the gap battery discharge couldn't fill
        $genH1 = $result['generator_used'][1];
        $this->assertGreaterThan(0.0, $genH1, 'Generator must run to cover the deficit above battery discharge rate');
        $this->assertLessThan(8400.0, $genH1,
            'Generator must NOT be boosted to 60 % when $dischargeW > 0 (outer guard blocks Case C)');

        $this->assertSame(0.0, $result['battery_charged_gen'][1],
            'No gen→battery charging when battery is discharging in the same hour');
    }

    // ── Test 12 — Case B suppressed on solar-rich morning ─────────────────────

    /**
     * 12.  h=6 is a morning daylight hour with the generator running at ~56 % load.
     *      Solar from h=7 to h=17 is enormous (20 kW/h surplus) — far more than
     *      the 7.22 kWh battery gap.  $solarStillCoversTarget must be TRUE at h=6,
     *      so Case B must be suppressed (no wasteful generator→battery charging).
     *
     *      This is the regression guard against the original bug that boosted the
     *      generator on solar-rich days when the battery would have filled naturally.
     */
    public function test_case_b_suppressed_on_solar_rich_morning(): void
    {
        // Night load h=18-23 → small battery target (~7.22 kWh at 6×1 kW night load)
        $loadW = array_fill(0, 24, 0.0);
        $loadW[6] = 8000.0;            // morning load, gen needed
        for ($h = 18; $h < 24; $h++) {
            $loadW[$h] = 1000.0;       // 1 kW × 6 h night load → battTarget ≈ 7.22 kWh
        }

        // Solar: sunrise h=6 (tiny — 100 W), then 20 kW for h=7-17
        $solarW = array_fill(0, 24, 0.0);
        $solarW[6] = 100.0;
        for ($h = 7; $h <= 17; $h++) {
            $solarW[$h] = 20000.0;
        }

        // Battery depleted (solar from h=7+ will charge naturally; no gen boost needed)
        $batteries = $this->battery(usableKwh: 10.0, socFraction: 0.0);

        $result = $this->go($loadW, 14000.0, $batteries, $solarW);

        // At h=6: gen runs at (8000-100)/14000 ≈ 56 % — sub-efficient but solar can cover gap
        // solarStillCoversTarget = true → Case B suppressed → no battery_charged_gen[6]
        $this->assertSame(0.0, $result['battery_charged_gen'][6],
            'Case B must be suppressed at h=6 when remaining solar (h=7-17, 20 kW each) can fill the battery target');

        // Generator output must equal the load-serving fraction only (no boost)
        $this->assertEqualsWithDelta(
            7900.0,                       // 8000 - 100 W solar
            $result['generator_used'][6],
            50.0,
            'Generator must not exceed load-serving output when Case B is suppressed'
        );
    }

    // ── Test 13 — Case B fires at midday when remaining solar insufficient ─────

    /**
     * 13.  h=11 is a midday daylight hour with gen at 53.6 % load.  Solar from
     *      h=12 onward is zero (sunsetH=11 is the last daylight hour), so the
     *      remaining solar surplus (0 kWh) cannot cover the 7.1 kWh battery gap.
     *      $solarStillCoversTarget must be FALSE at h=11 → Case B fires.
     *
     *      This closes the h=9/11/12/14 gap identified in the July 13 dispatch.
     */
    public function test_case_b_fires_at_midday_when_remaining_solar_insufficient(): void
    {
        // Solar only h=5-11 (sparse 500 W), nothing after h=11 → sunsetH = 11
        $solarW = array_fill(0, 24, 0.0);
        for ($h = 5; $h <= 11; $h++) {
            $solarW[$h] = 500.0; // 500 W → daylight (> 50 W threshold)
        }

        // Night load h=18-23: 2 kW each → battTargetKwh ≈ 10 kWh (capped at capacity)
        $loadW = array_fill(0, 24, 0.0);
        $loadW[11] = 8000.0;          // 8 kW at h=11; gen runs at (8000-500)/14000 ≈ 53.6 %
        for ($h = 18; $h < 24; $h++) {
            $loadW[$h] = 2000.0;
        }

        // Battery starts depleted; h=5-10 sparse solar charges ≈ 2.9 kWh by h=11
        $batteries = $this->battery(usableKwh: 10.0, socFraction: 0.0);

        $result = $this->go($loadW, 14000.0, $batteries, $solarW);

        // Battery must have been charged this hour by the generator spare
        $this->assertGreaterThan(0.0, $result['battery_charged_gen'][11],
            'Case B must boost the generator at h=11 when no remaining solar can fill the battery target');

        // Generator must have been boosted toward 60 %
        $this->assertGreaterThan(
            7500.0, // load-serving baseline
            $result['generator_used'][11],
            'Generator output must exceed load-serving baseline when Case B fires'
        );

        $this->assertEqualsWithDelta(
            8400.0, // 60 % of 14 kW
            $result['generator_used'][11],
            100.0,
            'Generator must be boosted to approximately 60 % by Case B'
        );
    }

    // ── Test 14 — Case A still fires correctly; Case C path NOT taken ──────────

    /**
     * 14.  Generator runs at 78.6 % (11 kW on a 14 kW gen) at night — already
     *      above the 60 % efficient threshold.  Case A fires (spare to 85 % ceiling)
     *      and battery is charged.  Case C is never evaluated because the
     *      genLoadFraction ≥ GEN_MIN_EFFICIENT_LOAD branch is taken first.
     */
    public function test_case_a_fires_at_night_and_case_c_is_not_taken(): void
    {
        $batteries = $this->battery(usableKwh: 10.0, socFraction: 0.0, chargeKw: 10.0);

        $loadW    = array_fill(0, 24, 0.0);
        $loadW[1] = 11000.0; // 11 kW → 78.6 % of 14 kW gen (above 60 % efficient threshold)

        $result = $this->go($loadW, 14000.0, $batteries);

        // Case A: spare = 0.85 × 14000 - 11000 = 900 W → battery charges with 900 W
        $this->assertGreaterThan(0.0, $result['battery_charged_gen'][1],
            'Case A must charge battery when gen is already at ≥ 60 % efficient load');

        // Generator must reach the 85 % optimal ceiling (11000 + 900 = 11900 W)
        $this->assertEqualsWithDelta(
            11900.0,
            $result['generator_used'][1],
            50.0,
            'Case A must boost generator to 85 % ceiling, not beyond'
        );

        // Confirm generator did NOT overshoot 85 %
        $this->assertLessThanOrEqual(
            14000.0 * 0.85 + 1.0,
            $result['generator_used'][1],
            'Generator must not exceed GEN_OPTIMAL_MAX_LOAD = 85 % ceiling'
        );

        // No unmet demand (11 kW load is served)
        $this->assertEqualsWithDelta(0.0, $result['unmet'][1], 1.0,
            'Full load must be served at h=1');
    }
}
