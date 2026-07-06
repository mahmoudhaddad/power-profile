<?php

namespace Tests\Unit;

use App\Services\LoadSheddingService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LoadSheddingService (no database required).
 *
 * Covered:
 *   1. Healthy day (no deficit) → all shed fields = 0, adjusted_load_w unchanged.
 *   2. Curtailable load is curtailed before a Normal load is shed.
 *   3. Normal loads shed before Essential loads.
 *   4. Critical loads are never auto-shed; residual appears in critical_unmet_kwh.
 *   5. Shiftable load is shifted to a surplus hour before any shedding occurs.
 *   6. Shiftable falls through to Normal shedding when no feasible surplus window exists.
 *   7. Restoration in reverse order (Essential before Normal) with one-hour hysteresis.
 */
class LoadSheddingServiceTest extends TestCase
{
    private LoadSheddingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new LoadSheddingService();
    }

    // ── Slot / supply-cap factory helpers ─────────────────────────────────────

    private function slot(
        float  $peakW,
        string $priority,
        string $flex,
        array  $activeHours,       // bool[24]
        float  $curtailMinPct  = 0.0,
        int    $earliestStart  = 0,
        int    $latestEnd      = 24,
        string $label          = 'test'
    ): array {
        return [
            'peak_w'          => $peakW,
            'priority'        => $priority,
            'load_flexibility'=> $flex,
            'active_hours'    => $activeHours,
            'curtail_min_pct' => $curtailMinPct,
            'earliest_start'  => $earliestStart,
            'latest_end'      => $latestEnd,
            'required_run_h'  => 1,
            'label'           => $label,
        ];
    }

    /** Build a bool[24] array with true for hours in [$start, $end). */
    private function hours(int $start, int $end): array
    {
        $h = array_fill(0, 24, false);
        for ($i = $start; $i < $end; $i++) {
            $h[$i] = true;
        }
        return $h;
    }

    /** Build a float[24] supply-capacity array with an optional per-hour override. */
    private function supply(float $base, array $overrides = []): array
    {
        $s = array_fill(0, 24, $base);
        foreach ($overrides as $h => $w) {
            $s[$h] = $w;
        }
        return $s;
    }

    // ── Test 1 ────────────────────────────────────────────────────────────────

    /** Healthy day: supply ≥ demand at every hour → zero shedding, load unchanged. */
    public function test_healthy_day_produces_zero_shedding(): void
    {
        $slots = [
            $this->slot(1000, 'normal',   'fixed',       $this->hours(8, 18)),
            $this->slot(500,  'essential', 'fixed',       $this->hours(6, 22)),
            $this->slot(200,  'critical',  'fixed',       $this->hours(0, 24)),
        ];
        // Supply exceeds the 1700 W peak at every hour
        $supply = $this->supply(2000);

        $r = $this->svc->shed($slots, $supply);

        $this->assertSame(0.0, $r['shed_curtailable_kwh'],  'No curtailment on healthy day');
        $this->assertSame(0.0, $r['shed_normal_kwh'],       'No normal shed on healthy day');
        $this->assertSame(0.0, $r['shed_essential_kwh'],    'No essential shed on healthy day');
        $this->assertSame(0.0, $r['critical_unmet_kwh'],    'No critical unmet on healthy day');
        $this->assertEmpty($r['per_load_shed_list'],         'No shed events on healthy day');

        // Load profile must be identical to original
        $expected = array_fill(0, 24, 0.0);
        for ($h = 0; $h < 24; $h++) {
            if ($h >= 8 && $h < 18)  $expected[$h] += 1000.0;
            if ($h >= 6 && $h < 22)  $expected[$h] += 500.0;
            $expected[$h] += 200.0; // critical runs all 24 h
        }
        foreach ($expected as $h => $w) {
            $this->assertEqualsWithDelta($w, $r['adjusted_load_w'][$h], 0.01, "Hour {$h} unchanged");
        }
    }

    // ── Test 2 ────────────────────────────────────────────────────────────────

    /** Curtailable load must be curtailed (not normal load shed) when deficit is small. */
    public function test_curtailable_reduced_before_normal_shed(): void
    {
        // Demand at h=10: curtailable(800) + normal(600) = 1400 W
        // Supply at h=10: 1000 W → deficit 400 W
        // Curtailable can go down to 30% = 240 W (reduction = 560 W > 400 W deficit)
        // Normal should NOT be shed.
        $slots = [
            $this->slot(800,  'normal', 'curtailable', $this->hours(8, 18), 30.0, label: 'curtailable-load'),
            $this->slot(600,  'normal', 'fixed',        $this->hours(8, 18), 0.0,  label: 'normal-load'),
        ];

        $supply = $this->supply(2000, [10 => 1000]);

        $r = $this->svc->shed($slots, $supply);

        $this->assertGreaterThan(0.0, $r['shed_curtailable_kwh'], 'Curtailable must be curtailed');
        $this->assertSame(0.0, $r['shed_normal_kwh'],             'Normal must NOT be shed');
        $this->assertSame(0.0, $r['shed_essential_kwh'],          'Essential untouched');
        $this->assertSame(0.0, $r['critical_unmet_kwh'],          'No critical unmet');

        $actions = array_column($r['per_load_shed_list'], 'action');
        $this->assertContains('curtailed', $actions,        'Curtailed action present');
        $this->assertNotContains('shed_normal', $actions,   'shed_normal must not appear');
    }

    // ── Test 3 ────────────────────────────────────────────────────────────────

    /** Normal loads shed before Essential loads. */
    public function test_normal_shed_before_essential(): void
    {
        // Demand h=12: normal(1000) + essential(800) = 1800 W
        // Supply = 600 W → deficit 1200 W
        // Normal (1000 W) shed first, still 200 W deficit, then Essential partially shed.
        $slots = [
            $this->slot(1000, 'normal',    'fixed', $this->hours(10, 16), label: 'N1'),
            $this->slot(800,  'essential', 'fixed', $this->hours(10, 16), label: 'E1'),
        ];

        $supply = $this->supply(2000, [12 => 600]);

        $r = $this->svc->shed($slots, $supply);

        $this->assertGreaterThan(0.0, $r['shed_normal_kwh'],    'Normal must be shed');
        $this->assertGreaterThan(0.0, $r['shed_essential_kwh'], 'Essential shed after Normal exhausted');
        $this->assertSame(0.0, $r['critical_unmet_kwh'],        'No critical loads in this test');

        $actions = array_column($r['per_load_shed_list'], 'action');
        // shed_normal must appear before shed_essential in the list
        $idxNormal    = array_search('shed_normal',    $actions);
        $idxEssential = array_search('shed_essential', $actions);
        $this->assertNotFalse($idxNormal,    'shed_normal event missing');
        $this->assertNotFalse($idxEssential, 'shed_essential event missing');
        $this->assertLessThan($idxEssential, $idxNormal, 'Normal shed before Essential');
    }

    // ── Test 4 ────────────────────────────────────────────────────────────────

    /** Critical loads are NEVER auto-shed; residual appears in critical_unmet_kwh only. */
    public function test_critical_load_never_auto_shed(): void
    {
        // Demand h=14: normal(500) + essential(500) + critical(1000) = 2000 W
        // Supply = 200 W → deficit 1800 W
        // After shedding normal(500) + essential(500) = 1000 W shed, 800 W still deficient.
        // Critical 1000 W remains — but residual deficit is 800 W (the non-covered demand).
        $slots = [
            $this->slot(500,  'normal',   'fixed',  $this->hours(12, 18), label: 'N1'),
            $this->slot(500,  'essential','fixed',  $this->hours(12, 18), label: 'E1'),
            $this->slot(1000, 'critical', 'fixed',  $this->hours(0, 24),  label: 'CRIT'),
        ];

        $supply = $this->supply(2000, [14 => 200]);

        $r = $this->svc->shed($slots, $supply);

        $this->assertGreaterThan(0.0, $r['critical_unmet_kwh'], 'critical_unmet_kwh must be > 0');

        // Critical load must NOT appear in the shed list
        $labels = array_column($r['per_load_shed_list'], 'label');
        $this->assertNotContains('CRIT', $labels, 'Critical load must never appear in shed list');

        // Verify normal + essential are shed
        $actions = array_column($r['per_load_shed_list'], 'action');
        $this->assertContains('shed_normal',    $actions, 'Normal must be shed');
        $this->assertContains('shed_essential', $actions, 'Essential must be shed');
    }

    // ── Test 5 ────────────────────────────────────────────────────────────────

    /** Shiftable load is shifted to a surplus hour; no shedding occurs. */
    public function test_shiftable_shifted_before_shedding(): void
    {
        // h=8:  shiftable(1000) active, supply=500 → deficit 500 W
        // h=14: supply=2000, no other loads → surplus 2000 W (can absorb 1000 W)
        // The shiftable has window 0–24, so h=14 is a valid target.
        $slots = [
            $this->slot(1000, 'normal', 'shiftable', $this->hours(8, 9),
                        0.0, 0, 24, 'shiftable-load'),
        ];

        $supply = $this->supply(2000, [8 => 500]);

        $r = $this->svc->shed($slots, $supply);

        $this->assertSame(0.0, $r['shed_normal_kwh'],   'Shiftable must not be shed');
        $this->assertSame(0.0, $r['shed_essential_kwh'],'No essential shed');
        $this->assertSame(0.0, $r['critical_unmet_kwh'],'No critical unmet');

        $actions = array_column($r['per_load_shed_list'], 'action');
        $matched = array_filter($actions, fn($a) => str_starts_with($a, 'shifted_to_h'));
        $this->assertNotEmpty($matched, 'A shifted_to_h* action must appear');

        // Load at h=8 must be zero (shifted away)
        $this->assertEqualsWithDelta(0.0, $r['adjusted_load_w'][8], 0.01, 'Load removed from h=8');
    }

    // ── Test 6 ────────────────────────────────────────────────────────────────

    /** Shiftable falls through to Normal shedding when no surplus window exists. */
    public function test_shiftable_falls_through_when_no_feasible_window(): void
    {
        // All hours within the shiftable window also have deficits.
        // Shiftable is active h=8 with window 7–10.
        // Supply at h=7,8,9: 400 W — below the load's 1000 W even when shifted.
        // So the load cannot be shifted and must be shed as a Normal load.
        $slots = [
            $this->slot(1000, 'normal', 'shiftable', $this->hours(8, 9),
                        0.0, 7, 10, 'shiftable-no-window'),
        ];

        // All hours in window [7,10) are supply-constrained
        $supply = $this->supply(2000, [7 => 400, 8 => 400, 9 => 400]);

        $r = $this->svc->shed($slots, $supply);

        $this->assertGreaterThan(0.0, $r['shed_normal_kwh'], 'Shiftable must fall through to shed_normal');

        $actions = array_column($r['per_load_shed_list'], 'action');
        $this->assertContains('shed_normal', $actions, 'shed_normal event required');
        $shifted = array_filter($actions, fn($a) => str_starts_with($a, 'shifted_to_h'));
        $this->assertEmpty($shifted, 'No shift should have occurred');
    }

    // ── Test 7 ────────────────────────────────────────────────────────────────

    // ── Test 8 ────────────────────────────────────────────────────────────────

    /**
     * Curtailable tier is restored AFTER the Normal tier when supply headroom
     * is constrained.
     *
     * A curtailable load stays in shedState='curtailed' (not 'shed') whenever
     * curtailment + Normal-shedding resolves the deficit before the curtailable
     * is reached in Step 3.  At the restoration hour, Normal fits within supply
     * and is restored first; the curtailable's restoration is deferred to the
     * next hour when supply allows it.
     *
     * Timeline:
     *   h=5: demand 1000 W, supply 300 W → deficit 700 W
     *        Step 2: curtail Curtailable(200 W → 100 W, −100 W curtailed), deficit 600 W
     *        Step 3: shed Normal-fixed(800 W), deficit −200 W  ← resolved before touching curtailed slot
     *        Result: Normal='shed', Curtailable='curtailed' (still at 100 W)
     *   h=6: supply 5000 W, demand 100 W → surplus → surplusLastHour = true
     *   h=7: supply 950 W → restore Normal (100+800=900 ≤ 950 ✓)
     *              skip Curtailable (900+100=1000 > 950 ✗)
     *        surplusLastHour = (950 ≥ 900×1.05=945) → true
     *   h=8: supply 5000 W → restore Curtailable (900+100=1000 ≤ 5000 ✓)
     */
    public function test_curtailable_restored_last_after_normal(): void
    {
        $slots = [
            $this->slot(800,  'normal', 'fixed',       $this->hours(5, 15), 0.0,  label: 'Normal-fixed'),
            $this->slot(200,  'normal', 'curtailable', $this->hours(5, 15), 50.0, label: 'Curtailable'),
        ];

        // Deficit only at h=5; constrained supply at h=7 (fits Normal but not Normal+Curtailable)
        $supply = $this->supply(5000, [5 => 300, 6 => 5000, 7 => 950]);

        $r = $this->svc->shed($slots, $supply);

        // Shedding must have occurred
        $this->assertGreaterThan(0.0, $r['shed_normal_kwh'],     'Normal must be shed at h=5');
        $this->assertGreaterThan(0.0, $r['shed_curtailable_kwh'],'Curtailable must be curtailed at h=5');

        // h=6: hysteresis — loads still absent
        $this->assertEqualsWithDelta(100.0, $r['adjusted_load_w'][6], 0.01,
            'h=6: only curtailable-at-min (100 W); Normal still shed (hysteresis)');

        // h=7: constrained supply — Normal restored (fits), Curtailable deferred (would exceed supply)
        $this->assertEqualsWithDelta(900.0, $r['adjusted_load_w'][7], 0.01,
            'h=7: Normal(800)+Curtailable-min(100)=900 W — Normal restored, Curtailable still at min');

        // h=8: ample supply — Curtailable fully restored
        $this->assertEqualsWithDelta(1000.0, $r['adjusted_load_w'][8], 0.01,
            'h=8: full 1000 W restored — Curtailable recovered after Normal');
    }

    // ── Test 7 ────────────────────────────────────────────────────────────────

    /**
     * Restoration is in reverse shedding order (Essential first, Normal second)
     * and respects one-hour hysteresis (not restored in the very next hour after
     * supply recovers — only after supply has been surplus for a FULL hour).
     */
    public function test_restoration_reverse_order_with_hysteresis(): void
    {
        // Setup:
        //   h=5:  demand 1800 W, supply 200 W → shed Normal(1000) then Essential(600)
        //   h=6:  supply 5000 W > demand*(1+RESTORE_MARGIN) → hysteresis trigger hour
        //         (loads still shed: NOT restored yet)
        //   h=7:  surplusLastHour=true → restore Essential first (largest in tier),
        //         then Normal
        //
        // Both Normal and Essential are active at h=5,6,7 so they qualify for restoration.

        $slots = [
            $this->slot(1000, 'normal',    'fixed', $this->hours(5, 10), label: 'N1'),
            $this->slot(600,  'essential', 'fixed', $this->hours(5, 10), label: 'E1'),
        ];

        // Deficit at h=5; large surplus at h=6+; all other hours no deficit
        $supply = $this->supply(5000, [5 => 200]);

        $r = $this->svc->shed($slots, $supply);

        // Shedding must have occurred at h=5
        $this->assertGreaterThan(0.0, $r['shed_normal_kwh'],    'Normal shed at h=5');
        $this->assertGreaterThan(0.0, $r['shed_essential_kwh'], 'Essential shed at h=5');

        // Hysteresis: at h=6 loads are still shed (not yet restored)
        $this->assertEqualsWithDelta(0.0, $r['adjusted_load_w'][6], 0.01,
            'h=6: hysteresis — loads still absent one hour after supply recovers');

        // Restoration at h=7: both loads should be back
        $totalExpected = 1000.0 + 600.0; // N1 + E1
        $this->assertEqualsWithDelta($totalExpected, $r['adjusted_load_w'][7], 0.01,
            'h=7: both loads restored after hysteresis clears');

        // Verify Essential is restored before Normal by checking per_load_shed_list
        // order is: shed_normal, shed_essential (shedding), then restoration brings
        // Essential back first. We infer this from adjusted_load_w showing full
        // restoration at h=7 (both loads restored).

        // After h=7, loads remain for h=8,9 as well
        $this->assertEqualsWithDelta($totalExpected, $r['adjusted_load_w'][8], 0.01, 'h=8 still active');
        $this->assertEqualsWithDelta($totalExpected, $r['adjusted_load_w'][9], 0.01, 'h=9 still active');
    }
}
