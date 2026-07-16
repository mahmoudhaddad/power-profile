<?php

namespace Tests\Unit;

use App\Services\LoadSheddingService;
use PHPUnit\Framework\TestCase;

/**
 * Feature 2 — Cost-Priority restoration mode (1.2× fuel-cost-ratio gate + 85 % IEC ceiling).
 *
 * Generator fixture: 5 kW rated, F_rated=2.5 L/h, F₀=0.75 L/h, fuel=$1.00/L
 *
 * Efficiency breakeven at k=1.2:
 *   f* = F₀ / (0.2×F_rated + F₀) = 0.75 / (0.50 + 0.75) = 0.60  →  60 % = 3 000 W
 *   Below 60 %: avg cost/kWh > 1.2× optimal  →  inefficient zone, restoration BLOCKED by CP.
 *   Above 60 %: avg cost/kWh ≤ 1.2× optimal  →  efficient zone, restoration ALLOWED by CP.
 *   30 % is the wet-stack FLOOR (generator must not run BELOW that) — it is not a CP ceiling.
 *
 * Slot fixture (active h=8–10, no solar, no utility):
 *   #0  critical  500 W — never shed, never gated
 *   #1  essential 500 W — shed at h=8; bypasses cost gate; always restored
 *   #2  normal   1000 W — shed at h=8; cost gate blocks restoration in Cost-Priority
 *
 * rawUnmetW[8] = 1500 W → Step 3 sheds normal (1000 W), Step 4 sheds essential (500 W).
 * rawUnmetW[9] = 0    → surplus flag set; tryRestore fires at h=9 (applies from h=9 onward).
 *
 * At restoration (h=9):
 *   Restore essential: essential bypasses cost gate → restored in BOTH modes.
 *     After restore: effectiveLoad = 1000 W (critical 500 + essential 500)
 *   Restore normal:  estGenLoad = 1000 + 1000 = 2000 W  →  40 % of 5 kW
 *     F_est = 0.75 + 1.75×0.40 = 1.45 L/h
 *     avgCost = 1.45/2.00 = $0.725/kWh   vs   optCost = 2.5/5.0 = $0.500/kWh
 *     ratio  = 1.45 > 1.2  →  BLOCKED in Cost-Priority.
 *     (40 % < 60 % breakeven — generator would be in the inefficient zone)
 *     SP restores (no gate); CP withholds.
 *
 * Five invariants:
 *   1. Shedding phase (Steps 1–5) is bit-identical in both modes; essential always restored.
 *      CP adjusted_load[10] = 1000 W (critical + essential only).
 *      SP adjusted_load[10] = 2000 W (critical + essential + normal).
 *   2. Cost-Priority never creates new unmet demand (CP adjusted load ≤ SP at every hour).
 *   3. Cost-Priority saves fuel vs Service-Priority.
 *      SP h=9–10: 2000 W → 40 % → F=1.45 L/h → $1.45/h × 2h = $2.90
 *      CP h=9–10: 1000 W → 20 % → F=1.10 L/h → $1.10/h × 2h = $2.20   saving ≈ $0.70
 *   4. Service-Priority (empty/absent costOpts) is completely unaffected by the parameter.
 *   5. Cost-Priority never proactively sheds currently-served load when rawUnmet = 0.
 */
class CostPriorityRestorationTest extends TestCase
{
    private const GEN_CAP_W   = 5000.0;
    private const F_RATED_LPH = 2.5;
    private const F_NO_LOAD   = 0.75;
    private const FUEL_PRICE  = 1.0;    // $/L

    private LoadSheddingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new LoadSheddingService();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function hours(int $from, int $to): array
    {
        $h = array_fill(0, 24, false);
        for ($i = $from; $i <= $to; $i++) {
            $h[$i] = true;
        }
        return $h;
    }

    private function slots(): array
    {
        $base = [
            'curtail_min_pct'  => 0.0,
            'earliest_start'   => 0,
            'latest_end'       => 24,
            'required_run_h'   => 0,
            'load_flexibility' => 'fixed',
        ];
        return [
            array_merge($base, ['peak_w' => 500.0,  'priority' => 'critical',  'label' => 'critical-load',  'active_hours' => $this->hours(8, 10)]),
            array_merge($base, ['peak_w' => 500.0,  'priority' => 'essential', 'label' => 'essential-load', 'active_hours' => $this->hours(8, 10)]),
            array_merge($base, ['peak_w' => 1000.0, 'priority' => 'normal',    'label' => 'normal-load',    'active_hours' => $this->hours(8, 10)]),
        ];
    }

    /** rawUnmetW[8]=1500 forces shed of normal(1000)+essential(500); all other hours 0. */
    private function rawUnmetW(): array
    {
        $u    = array_fill(0, 24, 0.0);
        $u[8] = 1500.0;
        return $u;
    }

    private function costOpts(): array
    {
        return [
            'gen_cap_w'     => self::GEN_CAP_W,
            'f_rated_lph'   => self::F_RATED_LPH,
            'f_no_load_lph' => self::F_NO_LOAD,
            'fuel_price'    => self::FUEL_PRICE,
            'solar_w'       => array_fill(0, 24, 0.0),
            'util_cap_w'    => 0.0,
        ];
    }

    /** Estimate total generator fuel cost assuming generator is the sole source. */
    private function fuelCost(array $adjustedLoadW): float
    {
        $total  = 0.0;
        $pRated = self::GEN_CAP_W / 1000.0;
        foreach ($adjustedLoadW as $loadW) {
            if ($loadW < 1.0) continue;
            $frac   = min(1.0, ($loadW / 1000.0) / $pRated);
            $total += (self::F_NO_LOAD + (self::F_RATED_LPH - self::F_NO_LOAD) * $frac) * self::FUEL_PRICE;
        }
        return $total;
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Test 1: Shedding phase is identical in both modes; essential always restored.
     * The cost gate only withholds RESTORATION of normal/curtailable — it never affects
     * how the initial shedding steps (1–5) operate.
     */
    public function test_shedding_identical_and_essential_always_restored(): void
    {
        $sp = $this->svc->shed($this->slots(), $this->rawUnmetW());
        $cp = $this->svc->shed($this->slots(), $this->rawUnmetW(), [], $this->costOpts());

        // Shedding phase must be bit-identical regardless of costOpts.
        $this->assertSame($sp['shed_normal_kwh'],    $cp['shed_normal_kwh'],
            'shed_normal_kwh must be identical — cost gate must not affect shedding steps');
        $this->assertSame($sp['shed_essential_kwh'], $cp['shed_essential_kwh'],
            'shed_essential_kwh must be identical — cost gate must not affect shedding steps');
        $this->assertSame($sp['critical_unmet_kwh'], $cp['critical_unmet_kwh'],
            'critical_unmet_kwh must be identical — critical never shed in either mode');

        // Essential (slot #1) shed at h=8, must be restored at h=9 in BOTH modes.
        // Essential bypasses the cost gate entirely; it is also below the 85 % IEC ceiling.
        // CP adjusted_load_w[10] = 500 (critical) + 500 (essential) = 1000 W.
        $this->assertEqualsWithDelta(1000.0, $cp['adjusted_load_w'][10], 0.01,
            'CP must restore essential (bypasses cost gate); normal blocked (40 % < 60 % breakeven)');

        // SP restores both essential and normal — no gate.
        $this->assertEqualsWithDelta(2000.0, $sp['adjusted_load_w'][10], 0.01,
            'SP must restore essential + normal at h=10 (no cost gate in Service-Priority)');
    }

    /**
     * Test 2: Cost-Priority never creates new unmet demand.
     * CP adjusted load ≤ SP adjusted load at every hour, and ≤ rawCapacity.
     */
    public function test_cost_priority_never_creates_new_unmet_demand(): void
    {
        $rawUnmet = $this->rawUnmetW();
        $sp       = $this->svc->shed($this->slots(), $rawUnmet);
        $cp       = $this->svc->shed($this->slots(), $rawUnmet, [], $this->costOpts());

        $initialLoadW = array_fill(0, 24, 0.0);
        foreach ($this->slots() as $slot) {
            for ($h = 0; $h < 24; $h++) {
                if ($slot['active_hours'][$h]) {
                    $initialLoadW[$h] += $slot['peak_w'];
                }
            }
        }

        for ($h = 0; $h < 24; $h++) {
            $rawCap = max(0.0, $initialLoadW[$h] - ($rawUnmet[$h] ?? 0.0));
            $cpLoad = $cp['adjusted_load_w'][$h];
            $spLoad = $sp['adjusted_load_w'][$h];

            $this->assertLessThanOrEqual(
                $rawCap + 0.01, $cpLoad,
                "h={$h}: CP adjusted_load_w ({$cpLoad} W) must not exceed rawCapacity ({$rawCap} W)"
            );
            $this->assertLessThanOrEqual(
                $spLoad + 0.01, $cpLoad,
                "h={$h}: CP ({$cpLoad} W) must never serve more load than SP ({$spLoad} W)"
            );
        }
    }

    /**
     * Test 3: Cost-Priority saves fuel vs Service-Priority.
     *
     * SP restores normal at h=9 → gen at 2000 W (40 %) → F=1.45 L/h → $1.45/h.
     * CP withholds normal (40 % < 60 % breakeven, ratio=1.45 > 1.2) → 1000 W → F=1.10 L/h.
     * Over h=9–10 (2 hours): SP burns $2.90, CP burns $2.20 → saving ≈ $0.70.
     */
    public function test_cost_priority_saves_fuel_vs_service_priority(): void
    {
        $sp = $this->svc->shed($this->slots(), $this->rawUnmetW());
        $cp = $this->svc->shed($this->slots(), $this->rawUnmetW(), [], $this->costOpts());

        $spFuel = $this->fuelCost($sp['adjusted_load_w']);
        $cpFuel = $this->fuelCost($cp['adjusted_load_w']);

        $this->assertLessThanOrEqual(
            $spFuel + 1e-9, $cpFuel,
            "CP fuel ({$cpFuel}) must be ≤ SP fuel ({$spFuel})"
        );
        $this->assertGreaterThan(
            1e-9, $spFuel - $cpFuel,
            "CP must save a material amount of fuel — SP:{$spFuel} CP:{$cpFuel}"
        );
    }

    /**
     * Test 4: Service-Priority is completely unaffected by the $costOpts parameter.
     */
    public function test_service_priority_unaffected_by_new_parameter(): void
    {
        $slots    = $this->slots();
        $rawUnmet = $this->rawUnmetW();

        $default  = $this->svc->shed($slots, $rawUnmet);
        $explicit = $this->svc->shed($slots, $rawUnmet, [], []);

        $this->assertSame($default['shed_normal_kwh'],    $explicit['shed_normal_kwh'],
            'Empty costOpts must not alter shed_normal_kwh');
        $this->assertSame($default['shed_essential_kwh'], $explicit['shed_essential_kwh'],
            'Empty costOpts must not alter shed_essential_kwh');
        $this->assertSame($default['adjusted_load_w'],    $explicit['adjusted_load_w'],
            'Empty costOpts must produce bit-identical adjusted_load_w');
        $this->assertSame($default['per_load_shed_list'], $explicit['per_load_shed_list'],
            'Empty costOpts must not alter per_load_shed_list');
    }

    /**
     * Test 5: Cost-Priority NEVER proactively sheds currently-served load.
     *
     * When rawUnmetW is zero at every hour, there is no genuine deficit.
     * Neither SP nor CP should shed anything, and both must be bit-identical.
     * This is the key regression test against any reintroduction of rawUnmetW augmentation
     * (which would add artificial deficit to force the generator below some load fraction).
     *
     * The generator is running at 2500 W (50 % of rated) — in the inefficient zone
     * (50 % < 60 % breakeven) but with zero unmet demand.  CP must not shed it.
     */
    public function test_cost_priority_never_proactively_sheds_served_load(): void
    {
        $active = array_fill(0, 24, false);
        for ($h = 8; $h <= 16; $h++) {
            $active[$h] = true;
        }

        $slots = [[
            'peak_w'          => 2500.0,  // 50 % of GEN_CAP_W — inefficient zone
            'priority'        => 'normal',
            'load_flexibility'=> 'fixed',
            'active_hours'    => $active,
            'curtail_min_pct' => 0.0,
            'earliest_start'  => 0,
            'latest_end'      => 24,
            'required_run_h'  => 0,
            'label'           => 'served-normal-load',
        ]];

        $rawUnmet = array_fill(0, 24, 0.0);   // everything served — zero deficit
        $costOpts = $this->costOpts();

        $sp = $this->svc->shed($slots, $rawUnmet);
        $cp = $this->svc->shed($slots, $rawUnmet, [], $costOpts);

        $this->assertEqualsWithDelta(0.0, $sp['shed_normal_kwh'], 1e-9,
            'SP must not shed anything when rawUnmet is zero');
        $this->assertEqualsWithDelta(0.0, $cp['shed_normal_kwh'], 1e-9,
            'CP must not proactively shed currently-served load — only restoration is gated');

        $this->assertSame($sp['adjusted_load_w'], $cp['adjusted_load_w'],
            'CP and SP must be bit-identical when no deficit exists anywhere');
    }
}
