<?php

namespace Tests\Unit;

use App\Services\SourceDispatchService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1 generator wet-stack floor tests.
 *
 * Caterpillar white paper on wet-stacking: sustained operation below 30 % of
 * rated load causes unburnt fuel and carbon accumulation in exhaust systems.
 * The floor value is stored in GeneratorLine.min_load_pct and passed to dispatch()
 * as $genMinLoadFloorPct; this constant is the default when the column is null.
 *
 * Logic added at Step 6 of dispatchOptimized():
 *   If the remaining deficit < genMinLoadW (floor), the engine prefers exhausting
 *   available battery capacity over starting at a destructively low load fraction.
 *   If battery cannot fully cover the deficit, the generator still runs — but the
 *   hour is flagged in sub_optimal_loading_hours so operators can see it.
 *
 * dispatchBasic() (no battery) only flags; it cannot substitute anything.
 *
 * Tests 5–8 match the numbering already used across the invariant suite.
 */
class GenMinLoadFloorTest extends TestCase
{
    private SourceDispatchService $dispatch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatch = new SourceDispatchService();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function battery(
        float $usableKwh,
        float $socFraction,
        float $chargeKw    = 5.0,
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
        array  $loadW,
        float  $genCapW,
        Collection $batteries,
        float  $genFloorPct = 0.30,
        ?array $solarW      = null,
        float  $utilCapW    = 0.0
    ): array {
        return $this->dispatch->dispatch(
            $loadW,
            $solarW ?? array_fill(0, 24, 0.0),
            $utilCapW,
            $genCapW,
            $batteries,
            0.0, null,
            $genFloorPct
        );
    }

    // ── Test 5 ─────────────────────────────────────────────────────────────────

    /**
     * 5.  Tiny deficit (2 kW) is well below the 30 % floor of a 14 kW generator.
     *     With a fully charged 10 kWh battery available, the battery must absorb all
     *     3 hours of load and the generator must never start.
     *
     * Before fix: generator ran at 14.3 % per hour (wet-stacking risk).
     * After fix:  battery covers every hour; generator_used = 0 throughout.
     */
    public function test_tiny_deficit_below_gen_floor_uses_battery_not_generator(): void
    {
        // 10 kWh battery at 100 % SOC; night load 2 kW × 3 h = 6 kWh total — fits easily.
        $batteries = $this->battery(usableKwh: 10.0, socFraction: 1.0);

        $loadW            = array_fill(0, 24, 0.0);
        $loadW[0]         = $loadW[1] = $loadW[2] = 2000.0; // 2 kW for h=0-2

        $result = $this->go($loadW, 14000.0, $batteries, genFloorPct: 0.30);

        for ($h = 0; $h < 24; $h++) {
            $this->assertSame(
                0.0,
                $result['generator_used'][$h],
                "h=$h: generator must stay off when battery can fully cover the sub-floor deficit"
            );
        }

        $this->assertEmpty(
            $result['sub_optimal_loading_hours'],
            'No sub-optimal hours when battery absorbs every sub-floor deficit'
        );

        // Battery must have actually discharged
        $battKwh = array_sum($result['battery_discharged']) / 1000.0;
        $this->assertGreaterThan(0.0, $battKwh,
            'Battery must discharge to supply the load that the generator skipped');
    }

    // ── Test 6 ─────────────────────────────────────────────────────────────────

    /**
     * 6.  Battery genuinely depleted — sub-floor generator run is unavoidable, so it
     *     is flagged rather than silently accepted.
     *
     * Battery SOC = 5 % (0.5 kWh).  Night load = 3 kW.
     * Battery covers ~0.5 kW in Step 4; remaining 2.5 kW < floor 4.2 kW.
     * Step 6 borrow check: battery has ~0 left → cannot help → subOptLoad = true.
     * Generator runs at 17.9 % and is flagged in sub_optimal_loading_hours.
     */
    public function test_depleted_battery_forces_sub_optimal_gen_and_flags_it(): void
    {
        // eff = 1.0 for exact arithmetic (no round-trip loss obscuring the SOC drain).
        $batteries = $this->battery(usableKwh: 10.0, socFraction: 0.05, eff: 1.0);
        // current = 10 × 0.05 = 0.5 kWh — barely any charge left

        $loadW    = array_fill(0, 24, 0.0);
        $loadW[0] = 3000.0; // 3 kW at h=0 only

        $genCapW = 14000.0; // floor 30 % = 4.2 kW
        $result  = $this->go($loadW, $genCapW, $batteries, genFloorPct: 0.30);

        $genH0  = $result['generator_used'][0];
        $subOpt = $result['sub_optimal_loading_hours'];

        $this->assertGreaterThan(0.0, $genH0,
            'Generator must run when the battery is truly exhausted');
        $this->assertLessThan($genCapW * 0.30, $genH0,
            'Generator must be running below the wet-stack floor (genuinely unavoidable)');

        $this->assertArrayHasKey(0, $subOpt,
            'h=0 must appear in sub_optimal_loading_hours (battery depleted, gen below floor)');
        $this->assertCount(1, $subOpt,
            'Only h=0 has load; exactly one sub-optimal flag expected');

        $this->assertEqualsWithDelta(
            round($genH0 / $genCapW * 100, 1), $subOpt[0], 0.05,
            'Flag value must be the actual generator load percentage'
        );

        // Dispatch invariant still holds at h=0
        $battD  = $result['battery_discharged'][0] ?? 0.0;
        $rehy   = round($battD + $genH0 + ($result['unmet'][0] ?? 0.0), 2);
        $this->assertEqualsWithDelta(3000.0, $rehy, 1.0,
            'battery + gen + unmet must equal load (invariant holds even for flagged hours)');
    }

    // ── Test 7 ─────────────────────────────────────────────────────────────────

    /**
     * 7.  Step 7 gen→battery charging guard (GEN_MIN_EFFICIENT_LOAD = 60 %) is
     *     completely unaffected by the Phase-1 floor change.
     *
     * Setup: 10 kW generator, empty battery (0 % SOC), load = 8 kW.
     * Gen runs at 80 % for load — above both the 30 % floor AND the 60 % efficient
     * threshold.  Step 6 does not intervene (deficit = 8 kW ≥ floor 3 kW).
     * Step 7 Case A must still fire: spare capacity (0.5 kW) charges the battery.
     */
    public function test_step7_gen_battery_charging_guard_unaffected_by_floor_change(): void
    {
        // Empty battery: no discharge possible, but 10 kWh of headroom for charging.
        $batteries = $this->battery(usableKwh: 10.0, socFraction: 0.0);

        $loadW      = array_fill(0, 24, 0.0);
        $loadW[12]  = 8000.0; // 8 kW at h=12 only
        $genCapW    = 10000.0; // 10 kW; 60% = 6 kW, 85% = 8.5 kW, floor 30% = 3 kW

        $result = $this->go($loadW, $genCapW, $batteries, genFloorPct: 0.30);

        // Step 7 Case A: gen at 80% ≥ 60% efficient load; spare = 8.5 - 8 = 0.5 kW → battery
        $this->assertGreaterThan(0.0, $result['battery_charged_gen'][12],
            'Step 7 Case A must still charge battery when gen runs at ≥ 60 % efficient load');

        $this->assertGreaterThan(8000.0, $result['generator_used'][12],
            'Generator output must exceed 8 kW (extra 0.5 kW goes to battery charging)');

        // GEN_OPTIMAL_MAX_LOAD = 0.85 ceiling must still be respected
        $this->assertLessThanOrEqual(
            $genCapW * 0.85 + 1.0, // 1 W floating-point tolerance
            $result['generator_used'][12],
            'Generator must not exceed 85 % capacity ceiling (GEN_OPTIMAL_MAX_LOAD)'
        );

        // h=12 is above the 30 % floor → no sub-optimal flag
        $this->assertArrayNotHasKey(12, $result['sub_optimal_loading_hours'],
            'Generator at 80 % must not appear in sub_optimal_loading_hours');
    }

    // ── Test 8 ─────────────────────────────────────────────────────────────────

    /**
     * 8.  Basic dispatch (no battery) flags sub-floor generator hours for awareness
     *     even though there is nothing to substitute — the generator still runs.
     *
     *     This is the no-battery counterpart to Test 5.  Without storage, the only
     *     output of the floor logic is the flag — load is still fully served.
     */
    public function test_basic_dispatch_no_battery_flags_sub_optimal_gen_hours(): void
    {
        $loadW            = array_fill(0, 24, 0.0);
        $loadW[0]         = $loadW[1] = $loadW[2] = 2000.0; // 2 kW × 3 h

        // Dispatch with no battery at all
        $result = $this->dispatch->dispatch(
            $loadW,
            array_fill(0, 24, 0.0), // no solar
            0.0,     // no utility
            14000.0, // 14 kW gen; floor 30 % = 4.2 kW
            null,    // no battery
            0.0, null,
            0.30     // explicit floor
        );

        $subOpt = $result['sub_optimal_loading_hours'];

        // h=0,1,2: gen = 2 kW = 14.3 % < floor 4.2 kW → all three must be flagged
        $this->assertArrayHasKey(0, $subOpt, 'h=0 must be flagged (no battery, gen below floor)');
        $this->assertArrayHasKey(1, $subOpt, 'h=1 must be flagged');
        $this->assertArrayHasKey(2, $subOpt, 'h=2 must be flagged');

        // Generator still runs to serve the load (no battery to defer to)
        for ($h = 0; $h < 3; $h++) {
            $this->assertEqualsWithDelta(2000.0, $result['generator_used'][$h], 0.5,
                "Basic dispatch must still serve load at h=$h even when gen is sub-floor");
        }

        // Flag value stores the actual load percentage
        $this->assertEqualsWithDelta(
            round(2000.0 / 14000.0 * 100, 1), $subOpt[0], 0.05,
            'Sub-optimal flag must store actual generator load percentage'
        );

        // h=3-23: no load → generator never starts → no flags
        for ($h = 3; $h < 24; $h++) {
            $this->assertArrayNotHasKey($h, $subOpt,
                "h=$h has no load — generator off — must not be flagged");
        }
    }
}
