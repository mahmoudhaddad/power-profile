<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\ScheduleController;
use App\Services\LoadSheddingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for socket/controlled circuit-level splitting in buildSocketSlots().
 *
 * The controlled socket demand is split into sub-slots of ≤ SOCKET_CIRCUIT_CAP_W (2944 W)
 * each, matching the 16 A breaker cap used by ElectricalDesignService::packSocketCircuits()
 * (Section 8.5.7).  This allows LoadSheddingService to shed and restore individual
 * circuits instead of treating the entire building's socket load as one indivisible block.
 *
 * Covered:
 *  1. buildSocketSlots() splits controlled demand into ≤ 2944 W sub-slots summing
 *     exactly to the original controlled total (no energy gained or lost by splitting).
 *  2. A single monolithic 22 971 W slot cannot be partially restored under July-13
 *     headroom (avail ≤ 11.363 kW < 22.971 kW → zero restoration at every active hour).
 *  3. After splitting into 8 circuit-sized sub-slots, tryRestore() progressively serves
 *     3–4 circuits (~8.7–11.6 kW) at h=11, instead of zero.
 *  4. Restoration invariant: adjusted_load_w[h] ≤ rawCapacity[h] at every active hour
 *     (no new unmet demand created by restoration).
 *  5. No same-hour flicker: the circuit restored at h=9 is not shed again at h=10
 *     (verified by absence of shed events in per_load_shed_list at h=10).
 */
class SocketCircuitSplitTest extends TestCase
{
    /** 16 A × 230 V × 0.80 loading factor = SOCKET_CIRCUIT_CAP_W from ScheduleController. */
    private const CAP = 2944.0;

    /**
     * 24-element rawUnmetW array mirroring July 13 pre-pass values.
     * Source: shed_restoration_audit.php tinker run against project 18, July 13.
     */
    private const JULY13_UNMET = [
        // h=0-7: no deficit before work hours
        0, 0, 0, 0, 0, 0, 0, 0,
        // h=8: gen at 100%, battery full, solar insufficient → 15.369 kW unmet
        15369,
        // h=9-16: peak hours, gen at 100% still insufficient
        12622, 12781, 11608, 11868, 13085, 13010, 15690, 18715,
        // h=17-23: solar gone, battery covers night load
        0, 0, 0, 0, 0, 0, 0,
    ];

    private LoadSheddingService $svc;
    private object              $ctrl;
    private ReflectionMethod    $slotsMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc  = new LoadSheddingService();
        $rc         = new ReflectionClass(ScheduleController::class);
        $this->ctrl = $rc->newInstanceWithoutConstructor();
        $this->slotsMethod = $rc->getMethod('buildSocketSlots');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Minimal stdClass project accepted by buildSocketSlots (no DB needed). */
    private function project(?string $buildingType = null): object
    {
        $p                      = new \stdClass();
        $p->building_type       = $buildingType;
        $p->buildings           = [];
        $p->work_days           = ['monday'];
        $p->work_time_intervals = [['start' => '08:00', 'end' => '17:00']];
        return $p;
    }

    /** Invoke buildSocketSlots() via reflection. */
    private function buildSocketSlots(float $demandVa, ?string $buildingType = null): array
    {
        return $this->slotsMethod->invoke(
            $this->ctrl,
            ['connected_va' => $demandVa, 'demand_va' => $demandVa],
            $this->project($buildingType),
            'monday',
            7,
            false   // useMax = false → uses demand_va
        );
    }

    /**
     * Build a bool[24] with true for h=8–16 (08:00–17:00 with midpoint rule).
     * Mirrors the active_hours produced by buildSocketSlots for a Monday workday.
     */
    private function workHours(): array
    {
        $h = array_fill(0, 24, false);
        for ($i = 8; $i <= 16; $i++) {
            $h[$i] = true;
        }
        return $h;
    }

    /** Build the post-split sub-slots manually (mirrors the new buildSocketSlots logic). */
    private function splitSlots(float $totalW): array
    {
        $slots     = [];
        $remaining = round($totalW, 2);
        $n         = 0;
        while ($remaining > 0.005) {
            $n++;
            $slotW     = round(min($remaining, self::CAP), 2);
            $slots[]   = [
                'peak_w'          => $slotW,
                'priority'        => 'normal',
                'load_flexibility'=> 'fixed',
                'active_hours'    => $this->workHours(),
                'curtail_min_pct' => 0.0,
                'earliest_start'  => 0,
                'latest_end'      => 24,
                'required_run_h'  => 0,
                'label'           => "socket/controlled/{$n}",
            ];
            $remaining = round($remaining - $slotW, 2);
        }
        return $slots;
    }

    /** Build the old single-block monolithic slot (pre-fix baseline). */
    private function monolithicSlot(float $totalW): array
    {
        return [[
            'peak_w'          => round($totalW, 2),
            'priority'        => 'normal',
            'load_flexibility'=> 'fixed',
            'active_hours'    => $this->workHours(),
            'curtail_min_pct' => 0.0,
            'earliest_start'  => 0,
            'latest_end'      => 24,
            'required_run_h'  => 0,
            'label'           => 'socket/controlled',
        ]];
    }

    // ── Test 1 ────────────────────────────────────────────────────────────────

    /**
     * buildSocketSlots() must split controlled demand into ≤ 2944 W sub-slots whose
     * total peak_w equals the original controlled demand exactly.
     *
     * Scenario: demand_va = 10 000 W, default building type (fraction = 0.15)
     *   controlledW = 10 000 × 0.85 = 8 500 W
     *   Expected circuits: ceil(8 500 / 2 944) = 3
     *   Split: 2 944 + 2 944 + 2 612 = 8 500 W
     */
    public function test_controlled_demand_splits_into_circuit_sized_sub_slots(): void
    {
        $all = $this->buildSocketSlots(10_000.0);

        $controlled = array_values(array_filter(
            $all,
            fn($s) => str_starts_with($s['label'], 'socket/controlled/')
        ));

        $this->assertCount(3, $controlled,
            '8 500 W at 2 944 W cap → ceil(8500/2944) = 3 sub-slots');

        $totalPeakW = array_sum(array_column($controlled, 'peak_w'));
        $this->assertEqualsWithDelta(8_500.0, $totalPeakW, 0.01,
            'Sub-slot peak_w sum must equal original controlled demand (no energy lost)');

        foreach ($controlled as $idx => $slot) {
            $this->assertLessThanOrEqual(self::CAP, $slot['peak_w'],
                "Sub-slot {$idx} peak_w ({$slot['peak_w']} W) must not exceed 2 944 W cap");
            $this->assertMatchesRegularExpression(
                '/^socket\/controlled\/\d+$/', $slot['label'],
                "Sub-slot label must follow 'socket/controlled/{n}' pattern"
            );
            $this->assertSame('normal', $slot['priority'],
                'Each controlled sub-slot must remain priority=normal (sheddable)');
        }

        // The label index must run 1..N without gaps
        foreach ($controlled as $n => $slot) {
            $this->assertSame("socket/controlled/" . ($n + 1), $slot['label'],
                "Sub-slots must be labelled consecutively starting at /1");
        }
    }

    // ── Test 2 ────────────────────────────────────────────────────────────────

    /**
     * Pre-fix baseline: a single monolithic 22 971 W slot cannot be partially
     * restored when the best available headroom (h=11) is only 11.363 kW.
     * adjusted_load_w stays at zero for every active hour after h=8 shedding.
     */
    public function test_monolithic_slot_produces_zero_restoration_under_july13_headroom(): void
    {
        $r = $this->svc->shed(
            $this->monolithicSlot(22_971.0),
            self::JULY13_UNMET
        );

        // The whole 22 971 W slot is shed at h=8 (deficit=15 369 < 22 971 → over-shed).
        // avail[h=11] = 22 971 − 11 608 = 11 363 W < 22 971 W → cannot restore.
        for ($h = 8; $h <= 16; $h++) {
            $this->assertEqualsWithDelta(0.0, $r['adjusted_load_w'][$h], 0.01,
                "h={$h}: monolithic slot cannot be partially restored — slot larger than any headroom window");
        }
    }

    // ── Test 3 ────────────────────────────────────────────────────────────────

    /**
     * After splitting into 8 circuit-sized sub-slots (7 × 2944 + 1 × 2363 = 22 971 W):
     *   – h=8: 6 sub-slots shed (17 664 W); 2 sub-slots (5 307 W) remain active
     *   – h=9: tryRestore fires; 1 circuit (2 944 W) restored → 8 251 W served
     *   – h=11: tryRestore fires again; 1 more circuit restored → 11 195 W served (4 circuits)
     *
     * The user-facing invariant: at h=11 the algorithm serves ~8.7–11.6 kW (3–4 circuits)
     * instead of the pre-fix zero.
     */
    public function test_split_slots_enable_partial_restoration_at_h11(): void
    {
        $slots = $this->splitSlots(22_971.0);
        $this->assertCount(8, $slots,
            '22 971 W / 2944 W cap → 7 full circuits + 1 remainder = 8 sub-slots');

        $r = $this->svc->shed($slots, self::JULY13_UNMET);

        // h=11: algorithm should serve 3–4 circuits worth (8.7–11.6 kW)
        $this->assertGreaterThanOrEqual(8_700.0, $r['adjusted_load_w'][11],
            'h=11: at least 3 circuits (~8.7 kW) must be served with split sub-slots');
        $this->assertLessThanOrEqual(11_600.0, $r['adjusted_load_w'][11],
            'h=11: at most 4 circuits (~11.6 kW) fit within available headroom');

        // h=9: at least 1 circuit served (pre-fix: 0 W)
        $this->assertGreaterThan(2_900.0, $r['adjusted_load_w'][9],
            'h=9: at least 1 circuit must be restored (monolithic restores zero)');

        // Direct contrast: split always serves more load than monolithic at h=9 and h=11
        $rMono = $this->svc->shed($this->monolithicSlot(22_971.0), self::JULY13_UNMET);
        $this->assertGreaterThan($rMono['adjusted_load_w'][9],  $r['adjusted_load_w'][9],
            'Split serves more load than monolithic at h=9');
        $this->assertGreaterThan($rMono['adjusted_load_w'][11], $r['adjusted_load_w'][11],
            'Split serves more load than monolithic at h=11');
    }

    // ── Test 4 ────────────────────────────────────────────────────────────────

    /**
     * Restoration invariant: at every active hour h=8–16, adjusted_load_w[h] must
     * not exceed rawCapacity[h] = initialLoadW[h] − rawUnmetW[h].
     * Violating this would create new unmet demand that the supply cannot meet.
     */
    public function test_restoration_never_exceeds_raw_capacity_at_any_hour(): void
    {
        $controlledW = 22_971.0;
        $slots       = $this->splitSlots($controlledW);
        $r           = $this->svc->shed($slots, self::JULY13_UNMET);

        // initialLoadW[h=8-16] = 22 971 W (no background load in this synthetic scenario)
        for ($h = 8; $h <= 16; $h++) {
            $rawCapacity = $controlledW - (float) self::JULY13_UNMET[$h];
            $this->assertLessThanOrEqual(
                $rawCapacity + 0.01,
                $r['adjusted_load_w'][$h],
                "h={$h}: adjusted_load ({$r['adjusted_load_w'][$h]} W) must not exceed " .
                "rawCapacity ({$rawCapacity} W); restoring more would create new unmet demand"
            );
        }
    }

    // ── Test 5 ────────────────────────────────────────────────────────────────

    /**
     * No same-hour flicker: the circuit restored at h=9 must not be shed again at h=10
     * (hysteresis: the per-hour availableToRestore check keeps h=10 deficit at zero).
     * Verified by asserting no shed_normal events in per_load_shed_list at h=10.
     */
    public function test_no_same_hour_flicker_after_h9_restoration(): void
    {
        $slots = $this->splitSlots(22_971.0);
        $r     = $this->svc->shed($slots, self::JULY13_UNMET);

        // Confirm restoration at h=9 DID occur (otherwise the flicker test is vacuous)
        $this->assertGreaterThan(0.0, $r['adjusted_load_w'][9],
            'Pre-condition: h=9 must have load served (restoration must have happened)');

        // h=10: avail = (loadShedAtH − rawUnmet[10]) = (17 664 − 12 781) − restored = 1 939 W
        //        → no 2 944 W sub-slot fits → no restoration AND no new shed → deficit = 0
        $shedAtH10 = array_filter(
            $r['per_load_shed_list'],
            fn($e) => $e['hour'] === 10 && str_starts_with($e['action'], 'shed')
        );

        $this->assertEmpty($shedAtH10,
            'h=10 (immediately after h=9 restoration) must contain no shed events; ' .
            'the restored circuit must not be immediately undone');
    }
}
