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
 *   7. Curtailable tier restored after Normal when supply is constrained at restoration hour.
 *   8. Restoration in reverse order (Essential before Normal) with one-hour hysteresis.
 *   9. Energy-exhaustion: battery-only system with sufficient peak power but insufficient
 *      stored energy triggers shedding when rawUnmetW is derived from real dispatch.
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

    /**
     * Derive a rawUnmetW[24] array from a set of slots and a supply cap array.
     * Mirrors the information that SourceDispatchService's pre-pass would return
     * for a system with no battery SOC effects: rawUnmetW[h] = max(0, load[h] - cap[h]).
     */
    private function rawUnmet(array $slots, array $supplyCapW): array
    {
        $load = array_fill(0, 24, 0.0);
        foreach ($slots as $slot) {
            for ($h = 0; $h < 24; $h++) {
                if ($slot['active_hours'][$h]) {
                    $load[$h] += $slot['peak_w'];
                }
            }
        }
        $unmet = array_fill(0, 24, 0.0);
        for ($h = 0; $h < 24; $h++) {
            $unmet[$h] = max(0.0, $load[$h] - $supplyCapW[$h]);
        }
        return $unmet;
    }

    // ── Test 1 ────────────────────────────────────────────────────────────────

    /** Healthy day: supply ≥ demand at every hour → zero shedding, load unchanged. */
    public function test_healthy_day_produces_zero_shedding(): void
    {
        $slots = [
            $this->slot(1000, 'normal',   'fixed',   $this->hours(8, 18)),
            $this->slot(500,  'essential','fixed',   $this->hours(6, 22)),
            $this->slot(200,  'critical', 'fixed',   $this->hours(0, 24)),
        ];
        $supply = $this->supply(2000); // exceeds 1700 W peak at every hour

        $r = $this->svc->shed($slots, $this->rawUnmet($slots, $supply), $supply);

        $this->assertSame(0.0, $r['shed_curtailable_kwh'], 'No curtailment on healthy day');
        $this->assertSame(0.0, $r['shed_normal_kwh'],      'No normal shed on healthy day');
        $this->assertSame(0.0, $r['shed_essential_kwh'],   'No essential shed on healthy day');
        $this->assertSame(0.0, $r['critical_unmet_kwh'],   'No critical unmet on healthy day');
        $this->assertEmpty($r['per_load_shed_list'],        'No shed events on healthy day');

        $expected = array_fill(0, 24, 0.0);
        for ($h = 0; $h < 24; $h++) {
            if ($h >= 8 && $h < 18)  $expected[$h] += 1000.0;
            if ($h >= 6 && $h < 22)  $expected[$h] += 500.0;
            $expected[$h] += 200.0;
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
            $this->slot(800, 'normal', 'curtailable', $this->hours(8, 18), 30.0, label: 'curtailable-load'),
            $this->slot(600, 'normal', 'fixed',        $this->hours(8, 18), 0.0,  label: 'normal-load'),
        ];

        $supply = $this->supply(2000, [10 => 1000]);

        $r = $this->svc->shed($slots, $this->rawUnmet($slots, $supply), $supply);

        $this->assertGreaterThan(0.0, $r['shed_curtailable_kwh'], 'Curtailable must be curtailed');
        $this->assertSame(0.0, $r['shed_normal_kwh'],             'Normal must NOT be shed');
        $this->assertSame(0.0, $r['shed_essential_kwh'],          'Essential untouched');
        $this->assertSame(0.0, $r['critical_unmet_kwh'],          'No critical unmet');

        $actions = array_column($r['per_load_shed_list'], 'action');
        $this->assertContains('curtailed', $actions,      'Curtailed action present');
        $this->assertNotContains('shed_normal', $actions, 'shed_normal must not appear');
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

        $r = $this->svc->shed($slots, $this->rawUnmet($slots, $supply), $supply);

        $this->assertGreaterThan(0.0, $r['shed_normal_kwh'],    'Normal must be shed');
        $this->assertGreaterThan(0.0, $r['shed_essential_kwh'], 'Essential shed after Normal exhausted');
        $this->assertSame(0.0, $r['critical_unmet_kwh'],        'No critical loads in this test');

        $actions      = array_column($r['per_load_shed_list'], 'action');
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
        $slots = [
            $this->slot(500,  'normal',   'fixed', $this->hours(12, 18), label: 'N1'),
            $this->slot(500,  'essential','fixed', $this->hours(12, 18), label: 'E1'),
            $this->slot(1000, 'critical', 'fixed', $this->hours(0, 24),  label: 'CRIT'),
        ];

        $supply = $this->supply(2000, [14 => 200]);

        $r = $this->svc->shed($slots, $this->rawUnmet($slots, $supply), $supply);

        $this->assertGreaterThan(0.0, $r['critical_unmet_kwh'], 'critical_unmet_kwh must be > 0');

        $labels = array_column($r['per_load_shed_list'], 'label');
        $this->assertNotContains('CRIT', $labels, 'Critical load must never appear in shed list');

        $actions = array_column($r['per_load_shed_list'], 'action');
        $this->assertContains('shed_normal',    $actions, 'Normal must be shed');
        $this->assertContains('shed_essential', $actions, 'Essential must be shed');
    }

    // ── Test 5 ────────────────────────────────────────────────────────────────

    /** Shiftable load is shifted to a surplus hour; no shedding occurs. */
    public function test_shiftable_shifted_before_shedding(): void
    {
        // h=8:  shiftable(1000) active, supply=500 → deficit 500 W
        // h=14: supply=2000, no other loads → surplus (rawUnmetW=0, headroom=1000 W)
        $slots = [
            $this->slot(1000, 'normal', 'shiftable', $this->hours(8, 9),
                        0.0, 0, 24, 'shiftable-load'),
        ];

        $supply = $this->supply(2000, [8 => 500]);

        $r = $this->svc->shed($slots, $this->rawUnmet($slots, $supply), $supply);

        $this->assertSame(0.0, $r['shed_normal_kwh'],    'Shiftable must not be shed');
        $this->assertSame(0.0, $r['shed_essential_kwh'], 'No essential shed');
        $this->assertSame(0.0, $r['critical_unmet_kwh'], 'No critical unmet');

        $actions = array_column($r['per_load_shed_list'], 'action');
        $matched = array_filter($actions, fn($a) => str_starts_with($a, 'shifted_to_h'));
        $this->assertNotEmpty($matched, 'A shifted_to_h* action must appear');

        $this->assertEqualsWithDelta(0.0, $r['adjusted_load_w'][8], 0.01, 'Load removed from h=8');
    }

    // ── Test 6 ────────────────────────────────────────────────────────────────

    /** Shiftable falls through to Normal shedding when no feasible surplus window exists. */
    public function test_shiftable_falls_through_when_no_feasible_window(): void
    {
        // Shiftable active h=8 with window 7–10.  shiftCapW at h=7,8,9 = 400 W.
        // Even though rawUnmetW[7] = 0 (no load there), shiftCapW headroom is
        // 400 - 0 - 1000 = -600 W < 0 → h=7 and h=9 rejected as shift targets.
        // h=8 has rawUnmetW > 0 → also rejected.
        // Falls through to shed as Normal.
        $slots = [
            $this->slot(1000, 'normal', 'shiftable', $this->hours(8, 9),
                        0.0, 7, 10, 'shiftable-no-window'),
        ];

        $supply = $this->supply(2000, [7 => 400, 8 => 400, 9 => 400]);

        $r = $this->svc->shed($slots, $this->rawUnmet($slots, $supply), $supply);

        $this->assertGreaterThan(0.0, $r['shed_normal_kwh'], 'Shiftable must fall through to shed_normal');

        $actions = array_column($r['per_load_shed_list'], 'action');
        $this->assertContains('shed_normal', $actions, 'shed_normal event required');
        $shifted = array_filter($actions, fn($a) => str_starts_with($a, 'shifted_to_h'));
        $this->assertEmpty($shifted, 'No shift should have occurred');
    }

    // ── Test 7 ────────────────────────────────────────────────────────────────

    /**
     * Curtailable tier is restored AFTER the Normal tier when supply headroom
     * is constrained at the restoration hour.
     *
     * Timeline:
     *   h=5: demand 1000 W, supply 300 W → deficit 700 W
     *        Step 2: curtail Curtailable(200→100 W, −100 W), deficit 600 W
     *        Step 3: shed Normal-fixed(800 W), deficit resolved
     *   h=6: surplus (rawUnmetW=0, loadShedSoFar=900) → surplusLastHour = true
     *   h=7: tryRestore — rawUnmetW=50 W (load=1000, supply=950)
     *        Normal(800 W): availableToRestore = 900−50 = 850 W → fits ✓ → restored
     *        Curtailable(100 W): after Normal, loadShedAtH=100, availableToRestore=50 → 100>50 ✗
     *   h=8: rawUnmetW=0 → availableToRestore=100 → Curtailable restored ✓
     */
    public function test_curtailable_restored_last_after_normal(): void
    {
        $slots = [
            $this->slot(800, 'normal', 'fixed',       $this->hours(5, 15), 0.0,  label: 'Normal-fixed'),
            $this->slot(200, 'normal', 'curtailable', $this->hours(5, 15), 50.0, label: 'Curtailable'),
        ];

        $supply = $this->supply(5000, [5 => 300, 6 => 5000, 7 => 950]);

        $r = $this->svc->shed($slots, $this->rawUnmet($slots, $supply), $supply);

        $this->assertGreaterThan(0.0, $r['shed_normal_kwh'],     'Normal must be shed at h=5');
        $this->assertGreaterThan(0.0, $r['shed_curtailable_kwh'],'Curtailable must be curtailed at h=5');

        // h=6: only curtailable-at-min active; Normal still shed (hysteresis)
        $this->assertEqualsWithDelta(100.0, $r['adjusted_load_w'][6], 0.01,
            'h=6: only curtailable-at-min (100 W); Normal still shed');

        // h=7: Normal restored (fits), Curtailable still deferred (rawUnmetW headroom too small)
        $this->assertEqualsWithDelta(900.0, $r['adjusted_load_w'][7], 0.01,
            'h=7: Normal(800)+Curtailable-min(100)=900 W — Normal restored, Curtailable still at min');

        // h=8: ample supply — Curtailable fully restored
        $this->assertEqualsWithDelta(1000.0, $r['adjusted_load_w'][8], 0.01,
            'h=8: full 1000 W restored after Curtailable recovery');
    }

    // ── Test 8 ────────────────────────────────────────────────────────────────

    /**
     * Restoration is in reverse shedding order (Essential first, Normal second)
     * and respects one-hour hysteresis (loads not restored in the same hour supply
     * recovers — only after supply has been surplus for a full hour).
     */
    public function test_restoration_reverse_order_with_hysteresis(): void
    {
        // h=5: demand 1600 W, supply 200 W → shed Normal(1000) then Essential(600)
        // h=6: rawUnmetW=0, but surplusLastHour from h=5 is false → tryRestore NOT called
        // h=7: surplusLastHour=true → restore Essential first, then Normal
        $slots = [
            $this->slot(1000, 'normal',    'fixed', $this->hours(5, 10), label: 'N1'),
            $this->slot(600,  'essential', 'fixed', $this->hours(5, 10), label: 'E1'),
        ];

        $supply = $this->supply(5000, [5 => 200]);

        $r = $this->svc->shed($slots, $this->rawUnmet($slots, $supply), $supply);

        $this->assertGreaterThan(0.0, $r['shed_normal_kwh'],    'Normal shed at h=5');
        $this->assertGreaterThan(0.0, $r['shed_essential_kwh'], 'Essential shed at h=5');

        // Hysteresis: at h=6 loads still shed (surplusLastHour was false at end of h=5)
        $this->assertEqualsWithDelta(0.0, $r['adjusted_load_w'][6], 0.01,
            'h=6: hysteresis — loads still absent one hour after supply recovers');

        // h=7: both Essential and Normal restored
        $this->assertEqualsWithDelta(1600.0, $r['adjusted_load_w'][7], 0.01,
            'h=7: both loads restored after hysteresis clears');

        $this->assertEqualsWithDelta(1600.0, $r['adjusted_load_w'][8], 0.01, 'h=8 still active');
        $this->assertEqualsWithDelta(1600.0, $r['adjusted_load_w'][9], 0.01, 'h=9 still active');
    }

    // ── Test 9 ────────────────────────────────────────────────────────────────

    /**
     * Energy-exhaustion scenario: battery-only system where peak-discharge power
     * would (incorrectly) show zero deficit at every hour, but the real dispatch
     * pre-pass reports genuine unmet watts at daytime peak hours because the battery's
     * stored kWh runs low.
     *
     * With the new rawUnmetW interface, shedding correctly acts on these hours.
     */
    public function test_energy_exhaustion_daytime_peak(): void
    {
        // Critical always-on (2 kW) + shiftable daytime-peak (10 kW, active h=10 only).
        // Battery battMaxDischargeW = 16,000 W — old supplyCapW approach would see 16 kW
        // everywhere and find zero deficit.  New approach: rawUnmetW comes from real dispatch
        // which reports 8 kW unmet at h=10 after the battery's stored kWh is exhausted.
        $slots = [
            $this->slot(2000,  'critical', 'fixed',     $this->hours(0, 24), label: 'CRIT'),
            $this->slot(10000, 'normal',   'shiftable', $this->hours(10, 11),
                        0.0, 6, 20, 'PEAK-SHIFTABLE'),
        ];

        // rawUnmetW from a real SOC-tracked dispatch: 8 kW unmet at h=10
        // (old supplyCapW-based array would have been: 16,000 W everywhere = 0 deficit)
        $rawUnmetW              = array_fill(0, 24, 0.0);
        $rawUnmetW[10]          = 8000.0;

        // shiftCapW: solar is available in the pre-peak window h=6–9
        $shiftCapW              = array_fill(0, 24, 0.0);
        for ($h = 6; $h < 10; $h++) {
            $shiftCapW[$h] = 15000.0;
        }

        $r = $this->svc->shed($slots, $rawUnmetW, $shiftCapW);

        // Shedding must have taken action (shift or shed)
        $actions  = array_column($r['per_load_shed_list'], 'action');
        $anyShed  = $r['shed_curtailable_kwh'] + $r['shed_normal_kwh'] + $r['shed_essential_kwh'] > 0;
        $anyShift = (bool) array_filter($actions, fn($a) => str_starts_with($a, 'shifted_to_h'));
        $this->assertTrue($anyShed || $anyShift,
            'Shedding must act on real dispatch deficit at h=10 (energy-exhaustion scenario)');

        // Critical load must never be shed
        $labels = array_column($r['per_load_shed_list'], 'label');
        $this->assertNotContains('CRIT', $labels, 'Critical load must never be shed');

        // After shedding/shifting, h=10 demand must be below original 12,000 W
        $this->assertLessThan(12000.0 - 0.01, $r['adjusted_load_w'][10],
            'Adjusted load at h=10 must be reduced from original 12,000 W');

        // critical_unmet_kwh = 0: the 2 kW critical load is below available supply
        // (rawUnmetW[10] = 8 kW means 8 kW non-critical was unmet, not critical)
        $this->assertSame(0.0, $r['critical_unmet_kwh'],
            'Critical unmet must be 0 after normal load action');
    }
}
