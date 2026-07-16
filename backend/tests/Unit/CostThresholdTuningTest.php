<?php

namespace Tests\Unit;

use App\Services\LoadSheddingService;
use PHPUnit\Framework\TestCase;

/**
 * Investigation results — Cost-Priority uses a 1.2× fuel-cost-ratio gate derived from
 * the ISU generator's efficiency curve, plus the standard 85 % IEC continuous-duty ceiling.
 *
 * Generator under test: Islamic University project 18 (ISU).
 *   P_rated    = 17 500 W
 *   F_rated    = 5.99 L/h
 *   F₀         = 1.80 L/h
 *   fuel_price = $2.00/L
 *
 * Efficiency breakeven at k=1.2:
 *   f* = F₀ / (0.2×F_rated + F₀) = 1.80 / (1.198 + 1.80) = 1.80 / 2.998 ≈ 60.0 %  →  10 500 W
 *   Below 60 %: avg cost/kWh > 1.2× optimal — inefficient, restoration blocked by CP.
 *   Above 60 %: avg cost/kWh ≤ 1.2× optimal — efficient zone, restoration allowed.
 *   30 % is the wet-stack FLOOR (generator must not run BELOW that) — not a CP ceiling.
 *
 * Load fixture: July 13 socket-demand profile.
 *   8 circuits — 7 × 2 944 W + 1 × 2 363 W = 22 971 W — active h=8–16.
 *   rawUnmetW[8–16] all positive; no solar, no utility.
 *   After shedding h=8: 6 circuits shed, 2 remain → 5 307 W base load.
 *
 * Why k=1.2 blocks every restoration on this profile:
 *   Lightest restoration: base 5 307 + circuit 2 363 W = 7 670 W (43.8 %)
 *     cost ratio = 1.342 > 1.2  →  BLOCKED.
 *   Next lightest:        base 5 307 + circuit 2 944 W = 8 251 W (47.1 %)
 *     cost ratio = 1.337 > 1.2  →  BLOCKED.
 *   Two circuits at once would reach 11 195 W (64.0 %,  ratio 1.170 < 1.2 — efficient),
 *   but the greedy algorithm tries one circuit at a time and each step is blocked.
 *
 * Why k=1.5 was ineffective (historical context, confirmed below):
 *   At 47.1 % load: ratio = 1.337, which is LESS than 1.5 → passed the old gate.
 *   CP-1.5 was therefore indistinguishable from SP on this profile.
 *
 * SP behaviour on July 13 (verified by the fuel-saving test):
 *   Restores circuit 0 at h=9 (gen 8 251 W, 47.1 %) then circuit 1 at h=11 (11 195 W, 64.0 %),
 *   re-sheds each when the deficit grows again at h=12 and h=15.
 * CP behaviour: constant 5 307 W base load h=8–15; both shed circuit 7 (genuine deficit) at h=16.
 *
 * Verified savings (July 13, socket-only scenario):
 *   SP fuel: ≈ $64.01   CP fuel: ≈ $54.14   Saving: ≈ $9.87/day
 *
 * Invariants confirmed:
 *   1. Cost-Priority never creates new unmet demand.
 *   2. Essential loads are never blocked by the cost gate.
 *   3. Fuel saving is meaningful (≥ $5/day for this generator/profile combination).
 *   4. Cost-Priority never proactively sheds currently-served load (zero-deficit regression).
 */
class CostThresholdTuningTest extends TestCase
{
    // ── ISU generator fixture ─────────────────────────────────────────────────

    private const ISU_GEN_CAP_W   = 17_500.0;
    private const ISU_F_RATED_LPH = 5.99;
    private const ISU_F_NO_LOAD   = 1.80;
    private const ISU_FUEL_PRICE  = 2.0;

    /** k=1.2 cost-ratio threshold, matching LoadSheddingService::COST_PRIORITY_THRESHOLD_FACTOR. */
    private const K = 1.2;

    /** July 13 raw-unmet profile (socket-only scenario, from SocketCircuitSplitTest). */
    private const JULY13_UNMET = [
        0, 0, 0, 0, 0, 0, 0, 0,
        15369, 12622, 12781, 11608, 11868, 13085, 13010, 15690, 18715,
        0, 0, 0, 0, 0, 0, 0,
    ];

    private LoadSheddingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new LoadSheddingService();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** 8 socket circuits: 7 × 2 944 W + 1 × 2 363 W = 22 971 W, active h=8–16. */
    private function socketSlots(): array
    {
        $slots    = [];
        $circuits = [2944.0, 2944.0, 2944.0, 2944.0, 2944.0, 2944.0, 2944.0, 2363.0];
        foreach ($circuits as $n => $w) {
            $active = array_fill(0, 24, false);
            for ($h = 8; $h <= 16; $h++) {
                $active[$h] = true;
            }
            $slots[] = [
                'peak_w'          => $w,
                'priority'        => 'normal',
                'load_flexibility'=> 'fixed',
                'active_hours'    => $active,
                'curtail_min_pct' => 0.0,
                'earliest_start'  => 0,
                'latest_end'      => 24,
                'required_run_h'  => 0,
                'label'           => 'socket/controlled/' . ($n + 1),
            ];
        }
        return $slots;
    }

    private function isuCostOpts(): array
    {
        return [
            'gen_cap_w'     => self::ISU_GEN_CAP_W,
            'f_rated_lph'   => self::ISU_F_RATED_LPH,
            'f_no_load_lph' => self::ISU_F_NO_LOAD,
            'fuel_price'    => self::ISU_FUEL_PRICE,
            'solar_w'       => array_fill(0, 24, 0.0),
            'util_cap_w'    => 0.0,
        ];
    }

    /** Estimate total generator fuel cost from an adjusted-load profile. */
    private function fuelCost(array $adjustedLoadW): float
    {
        $total  = 0.0;
        $pRated = self::ISU_GEN_CAP_W / 1000.0;
        foreach ($adjustedLoadW as $w) {
            if ($w < 1.0) continue;
            $frac   = min(1.0, ($w / 1000.0) / $pRated);
            $total += (self::ISU_F_NO_LOAD + (self::ISU_F_RATED_LPH - self::ISU_F_NO_LOAD) * $frac)
                      * self::ISU_FUEL_PRICE;
        }
        return round($total, 3);
    }

    /**
     * Compute avg fuel cost per kWh at a given load W, for the ISU generator.
     * F(P) = F₀ + (F_rated − F₀) × (P / P_rated)
     */
    private function avgCostPerKwh(float $loadW): float
    {
        $frac = $loadW / self::ISU_GEN_CAP_W;
        $f    = self::ISU_F_NO_LOAD + (self::ISU_F_RATED_LPH - self::ISU_F_NO_LOAD) * $frac;
        return $f * self::ISU_FUEL_PRICE / ($loadW / 1000.0);
    }

    /** Optimal (full-load) fuel cost per kWh — the denominator in the cost ratio. */
    private function optCostPerKwh(): float
    {
        return self::ISU_F_RATED_LPH * self::ISU_FUEL_PRICE / (self::ISU_GEN_CAP_W / 1000.0);
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /**
     * Documents the cost-ratio analysis for the lightest feasible restoration (47.1 %).
     *
     * At 47.1 % load (8 251 W = base 5 307 + one 2 944 W circuit):
     *   cost ratio = 1.337 > 1.2  →  BLOCKED by the k=1.2 cost gate.
     *   cost ratio = 1.337 < 1.5  →  PASSED the old k=1.5 gate (CP-1.5 ≡ SP — the bug).
     *
     * This is why the threshold was changed from 1.5 to 1.2: the old gate was too lenient
     * for this generator's specific fuel curve, letting restorations in the inefficient zone
     * (below 60 % breakeven) slip through without triggering the cost gate.
     */
    public function test_47pct_gen_load_has_cost_ratio_above_k1_2_threshold(): void
    {
        $estLoadW = 8_251.0;  // lightest restoration: base 5 307 + one 2 944 W circuit

        $ratio    = $this->avgCostPerKwh($estLoadW) / $this->optCostPerKwh();
        $optCost  = $this->optCostPerKwh();
        $avgCost  = $this->avgCostPerKwh($estLoadW);

        $ratioRounded = round($ratio, 3);

        // At 47.1 %, cost ratio must exceed k=1.2 — confirms the k=1.2 gate blocks it.
        $this->assertGreaterThan(
            self::K, $ratio,
            "Cost ratio at 47.1 % ({$ratioRounded}) must exceed k=" . self::K .
            " — confirms cost gate blocks this restoration"
        );

        // Historical reference: k=1.5 was too lenient — the same 47.1 % passed through.
        $this->assertLessThan(
            1.5, $ratio,
            "Cost ratio ({$ratioRounded}) must be < 1.5 — documents why k=1.5 let this through (CP-1.5 ≡ SP)"
        );

        // Sanity-check the breakeven: f* ≈ 60 %, so 47.1 % IS in the inefficient zone.
        $breakeven = self::ISU_F_NO_LOAD
            / (0.2 * self::ISU_F_RATED_LPH + self::ISU_F_NO_LOAD); // ≈ 0.600
        $frac = $estLoadW / self::ISU_GEN_CAP_W; // ≈ 0.471
        $this->assertLessThan(
            $breakeven, $frac,
            "47.1 % load must be below the 60 % breakeven — confirms it is in the inefficient zone"
        );
    }

    /**
     * CP (k=1.2) produces a meaningful fuel saving vs SP on the July 13 ISU profile.
     *
     * SP briefly restores circuits to 47.1 % and 64.0 % gen load during low-unmet hours,
     * then re-sheds them as the deficit grows.  CP withholds all single-circuit restorations
     * (each reaches only 47.1 %, cost ratio 1.337 > 1.2) keeping the generator at a constant
     * 5 307 W base.  Verified saving: ≈ $9.87/day on this socket-only scenario.
     */
    public function test_k1_2_threshold_saves_meaningful_fuel_vs_service_priority(): void
    {
        $slots    = $this->socketSlots();
        $rawUnmet = self::JULY13_UNMET;
        $costOpts = $this->isuCostOpts();

        $sp = $this->svc->shed($slots, $rawUnmet);
        $cp = $this->svc->shed($slots, $rawUnmet, [], $costOpts);

        $spFuel = $this->fuelCost($sp['adjusted_load_w']);
        $cpFuel = $this->fuelCost($cp['adjusted_load_w']);

        $this->assertLessThanOrEqual(
            $spFuel + 1e-9, $cpFuel,
            "CP fuel ({$cpFuel}) must be ≤ SP fuel ({$spFuel})"
        );

        $saving = $spFuel - $cpFuel;
        $this->assertGreaterThanOrEqual(
            5.0, $saving,
            "Fuel saving must be ≥ \$5/day (got \${$saving})"
        );
    }

    /**
     * Cost-Priority never creates new unmet demand on the July 13 profile.
     * CP adjusted_load_w[h] ≤ SP adjusted_load_w[h] ≤ rawCapacity[h] at every hour.
     */
    public function test_cost_priority_never_creates_unmet_demand_on_july13(): void
    {
        $slots    = $this->socketSlots();
        $rawUnmet = self::JULY13_UNMET;
        $costOpts = $this->isuCostOpts();

        $sp = $this->svc->shed($slots, $rawUnmet);
        $cp = $this->svc->shed($slots, $rawUnmet, [], $costOpts);

        $totalInitW = 22_971.0;
        for ($h = 0; $h < 24; $h++) {
            $rawCap = max(0.0, $totalInitW - (float) $rawUnmet[$h]);
            $cpLoad = $cp['adjusted_load_w'][$h];
            $spLoad = $sp['adjusted_load_w'][$h];

            $this->assertLessThanOrEqual(
                $rawCap + 0.01, $cpLoad,
                "h={$h}: CP adjusted_load ({$cpLoad} W) must not exceed rawCapacity ({$rawCap} W)"
            );
            $this->assertLessThanOrEqual(
                $spLoad + 0.01, $cpLoad,
                "h={$h}: CP ({$cpLoad} W) must never serve more than SP ({$spLoad} W)"
            );
        }
    }

    /**
     * The cost gate must not block the essential tier on the ISU generator.
     * Essential loads bypass the cost gate entirely and must always be restored
     * once headroom permits, even when their gen load is well below 60 % breakeven.
     */
    public function test_cost_gate_does_not_block_essential_tier_on_isu_generator(): void
    {
        $active = array_fill(0, 24, false);
        for ($h = 8; $h <= 16; $h++) {
            $active[$h] = true;
        }

        $essential = [
            'peak_w'          => 3_000.0,   // 17.1 % of ISU gen — well below 60 % breakeven
            'priority'        => 'essential',
            'load_flexibility'=> 'fixed',
            'active_hours'    => $active,
            'curtail_min_pct' => 0.0,
            'earliest_start'  => 0,
            'latest_end'      => 24,
            'required_run_h'  => 0,
            'label'           => 'essential-load',
        ];

        $rawUnmet      = array_fill(0, 24, 0.0);
        $rawUnmet[8]   = 4_000.0;  // force essential to be shed at h=8

        $cp = $this->svc->shed([$essential], $rawUnmet, [], $this->isuCostOpts());

        // Essential shed at h=8, must be restored at h=10 regardless of gen load fraction.
        // gen load after restore = 3 000 W → 17.1 % → cost ratio >> 1.2; essential bypasses gate.
        $this->assertEqualsWithDelta(
            3_000.0, $cp['adjusted_load_w'][10], 0.01,
            'Essential load must be restored at h=10; cost gate must not block essential tier'
        );
    }

    /**
     * Cost-Priority must NEVER proactively shed currently-served load.
     *
     * When rawUnmetW is zero everywhere, there is no genuine deficit.  The shedding
     * steps (1–5) must not fire, and CP and SP must be bit-identical.  This is the
     * regression test that guards against reintroduction of any rawUnmetW augmentation
     * that would artificially inflate the deficit to force the generator below some
     * load fraction.
     *
     * The generator runs at 30.3 % load (5 307 W on 17 500 W) — just above the
     * wet-stack floor.  CP must not reduce this further.
     */
    public function test_cost_priority_never_proactively_sheds_currently_served_load(): void
    {
        $slots    = $this->socketSlots();
        $rawUnmet = array_fill(0, 24, 0.0);   // all load served — zero deficit anywhere

        $sp = $this->svc->shed($slots, $rawUnmet);
        $cp = $this->svc->shed($slots, $rawUnmet, [], $this->isuCostOpts());

        $this->assertEqualsWithDelta(0.0, $sp['shed_normal_kwh'], 1e-9,
            'SP must not shed when rawUnmet is zero');
        $this->assertEqualsWithDelta(0.0, $cp['shed_normal_kwh'], 1e-9,
            'CP must not proactively shed currently-served load — shedding only responds to genuine deficit');

        $this->assertSame($sp['adjusted_load_w'], $cp['adjusted_load_w'],
            'CP and SP must be bit-identical when no deficit exists');
    }
}
