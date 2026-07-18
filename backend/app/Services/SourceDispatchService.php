<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Collection;

/**
 * Target-SOC look-ahead dispatch engine — charge to full, protect the night floor.
 *
 * Strategy (one-pass day-ahead, deterministic):
 *
 *   PRE-DISPATCH: scan the 24-hour load + solar profile to compute how much
 *   energy the battery must hold at sunset to cover all non-daylight hours
 *   (battery_target_kwh).  Add RESERVE_MARGIN (10 %) as a safety buffer.
 *   This is the FLOOR, never a ceiling.
 *
 *   DAYTIME (solar hours):
 *     • Solar surplus always charges the battery toward 100 % usable SOC.
 *     • Battery may discharge energy *above* battery_target_kwh to shave the
 *       daytime peak and displace generator fuel — the floor is protected.
 *     • If the generator is already running for load at ≥ 60 % rated, spare
 *       capacity charges the battery all the way to 100 % usable SOC (the
 *       headroom check is the only ceiling).  This builds a larger above-floor
 *       buffer for afternoon peak shaving and reduces generator start hours.
 *
 *   NIGHT (non-solar hours): discharge freely — the reserve was banked for
 *   this moment.  Generator fires only when battery is exhausted/insufficient.
 *
 * Priority order (highest → lowest):
 *   1. Solar (shared + paired)
 *   2. Battery discharge (capped at above-floor portion during daylight)
 *   3. Utility grid
 *   4. Generator (last resort)
 *   5. Generator spare → battery to full (≥ 60 % load, headroom is the ceiling)
 *
 * Utility charging is INTENTIONALLY excluded.
 * Without Time-of-Use tariff data the round-trip loss makes it a net cost.
 * Future: enable when ToU tariffs are added to the data model.
 */
class SourceDispatchService
{
    /**
     * Maximum generator loading fraction when opportunistically charging batteries.
     * Above 85 % load, wear rate rises and specific fuel consumption leaves the
     * optimal band.  The generator does NOT start solely to charge.
     */
    private const GEN_OPTIMAL_MAX_LOAD = 0.85;

    /**
     * Minimum generator load fraction required before spare capacity may charge
     * batteries.  Below this threshold the SFC penalty exceeds the value of stored
     * energy after the 15–20 % round-trip battery loss.
     */
    private const GEN_MIN_EFFICIENT_LOAD = 0.60; // ⚠ tunable

    /**
     * Inverter/rectifier one-way efficiency for the generator→battery AC→DC path.
     */
    private const INV_EFF = 0.95;

    /**
     * Solar output threshold (W) for classifying an hour as daylight.
     * Hours below this are treated as night for the look-ahead pre-pass.
     */
    private const SOLAR_PRESENCE_THRESHOLD = 50.0; // ⚠ tunable

    /**
     * Extra fraction of total usable battery capacity added on top of the
     * calculated night-energy requirement.  Guards against forecast errors
     * and battery aging (actual usable capacity < nameplate).
     */
    private const RESERVE_MARGIN = 0.10; // ⚠ tunable

    /**
     * Default minimum generator load fraction — Caterpillar wet-stack threshold.
     * Sustained operation below 30 % rated load causes unburnt-fuel accumulation
     * and carbon build-up in exhaust systems.  Read from GeneratorLine.min_load_pct
     * at the call site; this constant is the fallback when the column is null.
     */
    private const GEN_MIN_LOAD_FLOOR = 0.30;

    public function dispatch(
        array $loadW,
        array $solarW,
        float $utilityCapW,
        float $generatorCapW,
        ?Collection $batteries    = null,
        float $solarCapacityW     = 0.0,
        ?Collection $solarSystems  = null,
        float $genMinLoadFloorPct  = self::GEN_MIN_LOAD_FLOOR
    ): array {
        $activeBatteries = $batteries?->filter(fn($b) => $b->is_active && $b->usable_capacity_kwh > 0);

        if ($activeBatteries && $activeBatteries->isNotEmpty()) {
            $result = $this->dispatchOptimized(
                $loadW, $solarW, $utilityCapW, $generatorCapW,
                $activeBatteries, $solarCapacityW, $solarSystems,
                $genMinLoadFloorPct
            );
            $result['has_battery_storage'] = true;
            return $result;
        }

        return $this->dispatchBasic($loadW, $solarW, $utilityCapW, $generatorCapW, $genMinLoadFloorPct);
    }

    // ── Basic dispatch (no batteries) ────────────────────────────────────────

    private function dispatchBasic(
        array $loadW, array $solarW, float $utilityCapW, float $generatorCapW,
        float $genMinLoadFloorPct = self::GEN_MIN_LOAD_FLOOR
    ): array {
        $solarUsed = $utilityUsed = $generatorUsed = $unmet = [];
        $subOptimalLoadingHours = [];

        $genMinLoadW = $generatorCapW * $genMinLoadFloorPct;

        for ($h = 0; $h < 24; $h++) {
            $d = max(0.0, (float) ($loadW[$h]  ?? 0));
            $s = max(0.0, (float) ($solarW[$h] ?? 0));

            $sU = min($s, $d);           $d -= $sU;
            $uU = min($utilityCapW, $d); $d -= $uU;
            $gU = min($generatorCapW, $d); $d -= $gU;

            $solarUsed[$h]     = round($sU, 2);
            $utilityUsed[$h]   = round($uU, 2);
            $generatorUsed[$h] = round($gU, 2);
            $unmet[$h]         = round(max(0.0, $d), 2);

            // No batteries in this path — flag sub-floor generator hours for operator awareness.
            if ($gU > 0 && $generatorCapW > 0 && $gU < $genMinLoadW) {
                $subOptimalLoadingHours[$h] = round($gU / $generatorCapW * 100, 1);
            }
        }

        $unmetHoursBasic = array_values(array_keys(array_filter($unmet, fn($v) => $v > 0)));
        $maxUnmetKwBasic = count($unmetHoursBasic) > 0
            ? round(max(array_map(fn($h) => $unmet[$h] / 1000.0, $unmetHoursBasic)), 3)
            : 0.0;

        return [
            'solar_used'                    => $solarUsed,
            'battery_remaining_capacity_kw' => array_fill(0, 24, 0.0),
            'utility_used'                  => $utilityUsed,
            'generator_used'                => $generatorUsed,
            'unmet'                         => $unmet,
            'unmet_hours'                   => $unmetHoursBasic,
            'max_unmet_kw'                  => $maxUnmetKwBasic,
            'battery_depleted_at_hour'      => null,
            'has_battery_storage'           => false,
            'sub_optimal_loading_hours'     => $subOptimalLoadingHours,
            'stats'                         => $this->basicStats($solarUsed, $utilityUsed, $generatorUsed, $unmet, $loadW, $solarW),
        ];
    }

    // ── Optimised dispatch (batteries + optional named solar systems) ─────────

    private function dispatchOptimized(
        array $loadW,
        array $solarW,
        float $utilityCapW,
        float $genCapW,
        Collection $batteries,
        float $solarCapacityW,
        ?Collection $solarSystems,
        float $genMinLoadFloorPct = self::GEN_MIN_LOAD_FLOOR
    ): array {
        // ── Solar system inverter capacities (W) ─────────────────────────────
        $sysInvCapW = [];
        if ($solarSystems && $solarSystems->isNotEmpty()) {
            foreach ($solarSystems as $sys) {
                $sysInvCapW[$sys->id] = $sys->capacity_kw * 1000.0;
            }
        }

        // ── Build per-battery mutable state ──────────────────────────────────
        $bst = [];
        foreach ($batteries as $b) {
            $sysId   = $b->solar_system_id;
            $invCapW = isset($sysInvCapW[$sysId]) ? $sysInvCapW[$sysId] : PHP_FLOAT_MAX;

            $bst[$b->id] = [
                'usable'    => (float) $b->usable_capacity_kwh,
                'current'   => (float) $b->usable_capacity_kwh * max(0.0, min(1.0, (float) $b->current_soc)),
                'charge_kw' => (float) $b->max_charge_power_kw,
                'disch_kw'  => (float) $b->max_discharge_power_kw,
                'eff'       => sqrt(max(0.5, (float) $b->round_trip_efficiency)),
                'sys_id'    => $sysId,
                'inv_cap_w' => $invCapW,
            ];
        }

        // ── Index: solar_system_id → [battery_ids] ───────────────────────────
        $sysToBank   = [];
        $unpairedIds = [];
        foreach ($bst as $bid => $b) {
            if ($b['sys_id']) {
                $sysToBank[$b['sys_id']][] = $bid;
            } else {
                $unpairedIds[] = $bid;
            }
        }

        // ── Solar system capacity ratios ──────────────────────────────────────
        $totalSolarCapW = max(1.0, $solarCapacityW);
        $sysRatios = [];
        if ($solarSystems && $solarSystems->isNotEmpty()) {
            foreach ($solarSystems as $sys) {
                $sysRatios[$sys->id] = ($sys->capacity_kw * 1000.0) / $totalSolarCapW;
            }
        }

        // ═══════════════════════════════════════════════════════════════════════
        // PRE-DISPATCH LOOK-AHEAD — compute night energy target
        //
        // Scans the full 24-hour profile BEFORE the hourly loop to determine
        // how much the battery must hold at sunset to cover post-sunset hours.
        // Pre-sunrise energy is deliberately excluded: those hours are served
        // by the free night discharge that runs BEFORE the first daylight hour,
        // so including them would set the floor too high at sunrise (where the
        // battery has legitimately discharged) and force the generator to cover
        // morning load gaps that the battery-above-reserve could handle.
        // ═══════════════════════════════════════════════════════════════════════

        $totalUsableKwh = array_sum(array_column($bst, 'usable'));

        // Average one-way discharge efficiency across all batteries
        $avgDischEff = count($bst) > 0
            ? array_sum(array_column($bst, 'eff')) / count($bst)
            : 0.90;

        // Identify daylight vs. night hours from the solar profile
        $isDaylightH = [];
        $sunriseH    = -1;
        $sunsetH     = -1;
        $solarPeakH  = 12;  // default to noon; updated below
        $solarPeakW  = 0.0;
        for ($h = 0; $h < 24; $h++) {
            $sw = (float)($solarW[$h] ?? 0);
            $isDaylightH[$h] = $sw > self::SOLAR_PRESENCE_THRESHOLD;
            if ($isDaylightH[$h] && $sunriseH === -1) { $sunriseH = $h; }
            if ($isDaylightH[$h])                     { $sunsetH  = $h; }
            if ($sw > $solarPeakW) { $solarPeakW = $sw; $solarPeakH = $h; }
        }

        // Post-sunset load only — this is the energy the battery must hold at sunset.
        // When sunsetH = -1 (no solar at all), every hour counts (h > -1 is always true).
        $nightEnergyKwh = 0.0;
        for ($h = 0; $h < 24; $h++) {
            if (!$isDaylightH[$h] && $h > $sunsetH) {
                $nightEnergyKwh += max(0.0, (float)($loadW[$h] ?? 0)) / 1000.0;
            }
        }

        // Battery energy needed to deliver night_energy_kwh to the load,
        // accounting for one-way discharge efficiency, plus RESERVE_MARGIN.
        $nightEnergyRequired = $nightEnergyKwh / max(0.01, $avgDischEff);
        $reserveKwh          = $totalUsableKwh * self::RESERVE_MARGIN;
        $battTargetKwh       = min($totalUsableKwh, $nightEnergyRequired + $reserveKwh);
        $battTargetSoc       = $totalUsableKwh > 0 ? $battTargetKwh / $totalUsableKwh : 0.0;

        // Step 7 Case B gate ($solarStillCoversTarget) is evaluated per-hour inside the
        // main dispatch loop — see the Step 7 block below.  The old single pre-dispatch
        // flag ($solarCoversTarget) was a frozen whole-day estimate that could not react
        // to the battery SOC already built by the time a given hour was reached.

        // ── Output arrays ─────────────────────────────────────────────────────
        $solarUsed              = array_fill(0, 24, 0.0);
        $battChrgSolar          = array_fill(0, 24, 0.0);
        $battChrgGen            = array_fill(0, 24, 0.0);
        $battDischarged         = array_fill(0, 24, 0.0);
        $battRemainingW         = array_fill(0, 24, 0.0);
        $utilityUsed            = array_fill(0, 24, 0.0);
        $genUsed                = array_fill(0, 24, 0.0);
        $unmet                  = array_fill(0, 24, 0.0);
        $socTrace               = array_fill(0, 24, 0.0);
        $genDaylightFlag        = array_fill(0, 24, false);
        $subOptimalLoadingHours = [];
        $battDepletedAt         = null;

        for ($h = 0; $h < 24; $h++) {
            $demand      = max(0.0, (float)($loadW[$h]  ?? 0));
            $solarTot    = max(0.0, (float)($solarW[$h] ?? 0));
            $isDay       = $isDaylightH[$h];
            $sharedSolar = $solarTot;

            // ── STEP 1: Paired solar systems charge their exclusive batteries ──
            foreach ($sysRatios as $sysId => $ratio) {
                $sysOutputW = $solarTot * $ratio;
                $bankIds    = $sysToBank[$sysId] ?? [];
                if (empty($bankIds) || $sysOutputW <= 0) continue;

                $totalHead = 0.0;
                foreach ($bankIds as $bid) {
                    $totalHead += max(0.0, $bst[$bid]['usable'] - $bst[$bid]['current']) * 1000.0;
                }

                $totalCharged = 0.0;
                if ($totalHead > 0) {
                    foreach ($bankIds as $bid) {
                        $head   = max(0.0, $bst[$bid]['usable'] - $bst[$bid]['current']) * 1000.0;
                        if ($head <= 0) continue;
                        $maxC   = min($bst[$bid]['charge_kw'] * 1000.0, $head);
                        $share  = $sysOutputW * ($head / $totalHead);
                        $actual = min($share, $maxC);
                        $bst[$bid]['current'] += ($actual / 1000.0) * $bst[$bid]['eff'];
                        $totalCharged         += $actual;
                    }
                }
                $sharedSolar       -= $totalCharged;
                $battChrgSolar[$h] += $totalCharged;
            }
            $sharedSolar = max(0.0, $sharedSolar);

            // ── STEP 2: Shared solar covers load ──────────────────────────────
            $solarUsed[$h] = min($sharedSolar, $demand);
            $surplus       = $sharedSolar - $solarUsed[$h];
            $remaining     = $demand       - $solarUsed[$h];

            // ── STEP 3: Surplus shared solar charges unpaired banks ────────────
            if ($surplus > 0 && !empty($unpairedIds)) {
                $totalHead = 0.0;
                foreach ($unpairedIds as $bid) {
                    $totalHead += max(0.0, $bst[$bid]['usable'] - $bst[$bid]['current']) * 1000.0;
                }
                if ($totalHead > 0) {
                    $maxPoolCharge = min(
                        $surplus,
                        array_sum(array_map(fn($bid) => $bst[$bid]['charge_kw'], $unpairedIds)) * 1000.0
                    );
                    $totalCharged = 0.0;
                    foreach ($unpairedIds as $bid) {
                        $head   = max(0.0, $bst[$bid]['usable'] - $bst[$bid]['current']) * 1000.0;
                        if ($head <= 0) continue;
                        $maxC   = min($bst[$bid]['charge_kw'] * 1000.0, $head);
                        $share  = $maxPoolCharge * ($head / $totalHead);
                        $actual = min($share, $maxC);
                        $bst[$bid]['current'] += ($actual / 1000.0) * $bst[$bid]['eff'];
                        $totalCharged         += $actual;
                    }
                    $battChrgSolar[$h] += $totalCharged;
                }
            }

            // ── STEP 4: Battery discharge → remaining load ─────────────────────
            //
            // Night-reserve protection (look-ahead gate):
            //   During daylight hours the battery may only discharge energy
            //   *above* the dynamic reserve floor.  At night the reserve was
            //   built for exactly this moment — discharge freely.
            //
            // Dynamic floor: sum only the non-daylight hours that still lie
            // AHEAD of the current hour.  As pre-sunrise hours pass they drop
            // out of the sum, lowering the floor and revealing above-floor
            // energy that the battery can legitimately discharge for morning
            // load gaps — without touching what is needed post-sunset.
            $remainingNightKwh = 0.0;
            for ($j = $h + 1; $j < 24; $j++) {
                if (!$isDaylightH[$j]) {
                    $remainingNightKwh += max(0.0, (float)($loadW[$j] ?? 0)) / 1000.0;
                }
            }
            $dynamicFloorKwh = min(
                $totalUsableKwh,
                $remainingNightKwh / max(0.01, $avgDischEff) + $reserveKwh
            );

            $totalCurrentStep4 = array_sum(array_column($bst, 'current'));
            $rateCapW = $totalCurrentStep4 > 0
                ? array_sum(array_map(fn($b) => min($b['disch_kw'] * 1000.0, $b['inv_cap_w']), $bst))
                : 0.0;

            // Initialise before the if-block so Step 7's gates can read it.
            $aboveReserveKwh = 0.0;

            if ($isDay) {
                // Only discharge energy above the dynamic floor.
                // Ceiling uses $avgDischEff so battery drain = exactly aboveReserveKwh
                // and SOC never dips below the floor due to efficiency loss.
                $aboveReserveKwh = max(0.0, $totalCurrentStep4 - $dynamicFloorKwh);
                $maxDischW = $aboveReserveKwh > 0
                    ? min($rateCapW, $aboveReserveKwh * $avgDischEff * 1000.0)
                    : 0.0;
            } else {
                // Night: discharge freely
                $maxDischW = $totalCurrentStep4 > 0
                    ? min($rateCapW, $totalCurrentStep4 * 1000.0)
                    : 0.0;
            }

            $dischargeW = 0.0;
            if ($remaining > 0 && $maxDischW > 0) {
                $dischargeW = min($remaining, $maxDischW);

                foreach ($bst as $bid => &$b) {
                    if ($b['current'] <= 0 || $totalCurrentStep4 <= 0) continue;
                    $share  = $dischargeW * ($b['current'] / $totalCurrentStep4);
                    $maxD   = min($b['disch_kw'] * 1000.0, $b['inv_cap_w'], $b['current'] * 1000.0);
                    $actual = min($share, $maxD);
                    $b['current'] -= ($actual / 1000.0) / max(0.5, $b['eff']);
                    if ($b['current'] < 0.0) {
                        $b['current'] = 0.0;
                        if ($battDepletedAt === null) { $battDepletedAt = $h; }
                    }
                }
                unset($b);

                $battDischarged[$h] = $dischargeW;
                $remaining         -= $dischargeW;
            }

            $battRemainingW[$h] = max(0.0, $maxDischW - $dischargeW);

            // ── STEP 5: Utility ───────────────────────────────────────────────
            $utilityUsed[$h] = min($utilityCapW, $remaining);
            $remaining      -= $utilityUsed[$h];

            // ── STEP 6: Generator — last resort ──────────────────────────────
            //
            // Wet-stack floor (from GeneratorLine.min_load_pct, default 30 %):
            // When the deficit is smaller than genMinLoadW, prefer discharging
            // battery capacity that sits ABOVE the dynamic reserve floor (computed
            // in Step 4) over starting the generator at a destructively low load.
            //
            // Critical constraint: only borrow from the above-floor surplus.
            // Pulling below the floor would deprive later night hours of reserved
            // energy, potentially forcing the generator on at night — the opposite
            // of what this guard is intended to achieve.
            $genMinLoadW = $genCapW * $genMinLoadFloorPct;
            $subOptLoad  = false;

            if ($remaining > 0 && $genCapW > 0 && $remaining < $genMinLoadW) {
                $totalCurrBorrow     = array_sum(array_column($bst, 'current'));
                // Only the above-floor portion is available to borrow — protect the reserve.
                $aboveFloorForBorrow = max(0.0, $totalCurrBorrow - $dynamicFloorKwh);

                if ($aboveFloorForBorrow > 0) {
                    $rateCapBorrow = array_sum(array_map(
                        fn($b) => min($b['disch_kw'] * 1000.0, $b['inv_cap_w']),
                        $bst
                    ));
                    $availBattW = min($rateCapBorrow, $aboveFloorForBorrow * 1000.0);

                    if ($availBattW >= $remaining) {
                        // Above-floor battery can fully cover the sub-floor deficit — skip generator.
                        $borrowW = $remaining;
                        foreach ($bst as $bid => &$b) {
                            if ($b['current'] <= 0 || $totalCurrBorrow <= 0) continue;
                            $share  = $borrowW * ($b['current'] / $totalCurrBorrow);
                            $maxD   = min($b['disch_kw'] * 1000.0, $b['inv_cap_w'], $b['current'] * 1000.0);
                            $actual = min($share, $maxD);
                            $b['current'] -= ($actual / 1000.0) / max(0.5, $b['eff']);
                            if ($b['current'] < 0.0) {
                                $b['current'] = 0.0;
                                if ($battDepletedAt === null) { $battDepletedAt = $h; }
                            }
                        }
                        unset($b);
                        $battDischarged[$h] += $borrowW;
                        $remaining          -= $borrowW;
                    } else {
                        // Not enough above-floor capacity — generator runs below floor (unavoidable).
                        $subOptLoad = true;
                    }
                } else {
                    // Battery is at or below the reserve floor — generator is last resort.
                    $subOptLoad = true;
                }
            }

            $genUsed[$h] = min($genCapW, $remaining);
            $remaining  -= $genUsed[$h];
            if ($genUsed[$h] > 0 && $solarTot > 0) {
                $genDaylightFlag[$h] = true;
            }
            if ($subOptLoad && $genUsed[$h] > 0 && $genCapW > 0 && $genUsed[$h] < $genMinLoadW) {
                $subOptimalLoadingHours[$h] = round($genUsed[$h] / $genCapW * 100, 1);
            }

            // ── STEP 7: Generator spare → battery (charge to full usable SOC) ──
            //
            // Charges the battery when ALL of the following hold:
            //   (a) Generator already running for load this hour (never starts solely to charge)
            //   (b) Load-serving fraction ≥ GEN_MIN_EFFICIENT_LOAD (60 %): below that, SFC
            //       penalty exceeds the value of stored energy after battery round-trip loss
            //   (c) NOT an afternoon-ramp hour (post-solar-peak daylight with load > solar).
            //       In the afternoon ramp the battery should be DISCHARGING its above-floor
            //       buffer (Step 4) to displace generator fuel.  Charging it back here in
            //       the same hour would cancel the Step 4 discharge and pin SOC at ~100 %.
            //       The morning/pre-peak window still builds the above-floor buffer freely.
            //
            // The charge ceiling is 100 % usable SOC, not battery_target_kwh.
            // battery_target_kwh is the FLOOR for discharge (Step 4), not a charge cap.
            // A fuller battery builds a larger above-floor buffer for afternoon peak shaving
            // and reduces generator runtime in subsequent hours.
            // Natural stop: when headroom → 0, totalHead = 0, inner block is skipped.
            $genLoadFraction = $genCapW > 0 ? $genUsed[$h] / $genCapW : 0.0;

            // Morning pre-peak gate: between sunrise and solar peak, if the battery still
            // has above-floor energy, withhold generator spare — rising solar will charge
            // the battery naturally without burning extra fuel.
            $inMorningRamp = $isDay && $h < $solarPeakH && $aboveReserveKwh > 0.0;

            // Afternoon-ramp gate: suppress gen→battery when past solar peak, still daylight,
            // load exceeds solar, AND the battery actually has above-floor buffer to discharge.
            // The last condition prevents the gate from firing when the battery is depleted
            // (flat solar profiles set solarPeakH = first daylight hour, making every
            // subsequent hour "post-peak" even during a charging build-up phase).
            $inAfternoonRamp = $isDay && $h > $solarPeakH && $demand > $solarTot && $aboveReserveKwh > 0.0;

            // ── STEP 7: Generator → battery (efficient loading) ───────────────
            //
            // Case A — generator ≥ 60 % loaded (any hour): use spare up to 85 %.
            //   Generator already running efficiently; spare capacity goes to battery.
            //
            // Case B — generator < 60 % loaded, daylight only, solar-deficit hour:
            //   Boost generator output to exactly 60 % by adding battery charging.
            //   Gate: $solarStillCoversTarget must be false — evaluated per-hour from
            //   the remaining solar potential (h+1 → sunset) vs. the remaining battery
            //   gap (battTargetKwh − current SOC).  On solar-rich early-morning hours
            //   the gate stays true (solar can still fill the gap); by midday, when
            //   remaining daylight solar genuinely cannot cover the target, it flips
            //   and the boost fires.  This replaces the old frozen whole-day flag.
            //
            // Case C — generator < 60 % loaded, night hour:
            //   At night there is no solar to fill the battery naturally.  Boost the
            //   generator to exactly 60 % by adding battery charging.  Concentrates
            //   runtime at the efficient operating point, banks energy for subsequent
            //   night hours, and reduces total generator start-hours.
            //
            // Common guards (all cases):
            //   $genUsed[$h] > 0      — never start generator solely to charge
            //   $dischargeW === 0.0   — no same-hour round-trip
            //   !$inAfternoonRamp     — battery should discharge post-peak, not refill
            //   !$inMorningRamp       — solar rising, above-floor battery needs no help
            //   $totalHead > 0        — (inside block) stops naturally when battery full

            // Per-hour solar-coverage gate for Case B.
            // Remaining solar surplus from h+1 to sunset, compared against how much
            // of the battery target is still unfilled at this moment (after Steps 1–6).
            $solarStillCoversTarget = false;
            if ($isDay) {
                $remSolarSurplusKwh = 0.0;
                for ($j = $h + 1; $j < 24; $j++) {
                    if ($isDaylightH[$j]) {
                        $remSolarSurplusKwh += max(0.0,
                            ((float)($solarW[$j] ?? 0) - max(0.0, (float)($loadW[$j] ?? 0)))
                        ) / 1000.0;
                    }
                }
                $totalCurrentNow        = array_sum(array_column($bst, 'current'));
                $remainingGapKwh        = max(0.0, $battTargetKwh - $totalCurrentNow);
                $solarStillCoversTarget = ($remSolarSurplusKwh * $avgDischEff) >= $remainingGapKwh;
            }

            $spareGenW = 0.0;

            if ($genUsed[$h] > 0 && $genCapW > 0
                && $dischargeW === 0.0
                && !$inAfternoonRamp
                && !$inMorningRamp) {

                if ($genLoadFraction >= self::GEN_MIN_EFFICIENT_LOAD) {
                    // Case A: already at efficient threshold → spare up to optimal max
                    $spareGenW = max(0.0, $genCapW * self::GEN_OPTIMAL_MAX_LOAD - $genUsed[$h]);
                } elseif ($isDay && !$solarStillCoversTarget) {
                    // Case B: daylight, remaining solar cannot refill battery target → boost to 60 %
                    $spareGenW = max(0.0, $genCapW * self::GEN_MIN_EFFICIENT_LOAD - $genUsed[$h]);
                } elseif (!$isDay) {
                    // Case C: night, sub-efficient load → boost to 60 %; battery charging is the
                    // productive use (no solar available to fill naturally).
                    $spareGenW = max(0.0, $genCapW * self::GEN_MIN_EFFICIENT_LOAD - $genUsed[$h]);
                }
            }

            if ($spareGenW > 0) {
                $totalHead = 0.0;
                foreach ($bst as $b) {
                    $totalHead += max(0.0, $b['usable'] - $b['current']) * 1000.0;
                }
                if ($totalHead > 0) {
                    $totalChargeKwAll = array_sum(array_column($bst, 'charge_kw'));
                    $maxGenChargeAc   = min(
                        $spareGenW,
                        $totalChargeKwAll * 1000.0,
                        $totalHead / self::INV_EFF
                    );

                    $totalChargeAc = 0.0;
                    foreach ($bst as $bid => &$b) {
                        $head  = max(0.0, $b['usable'] - $b['current']) * 1000.0;
                        if ($head <= 0) continue;
                        $maxC  = min($b['charge_kw'] * 1000.0, $head / self::INV_EFF);
                        $share = $maxGenChargeAc * ($head / $totalHead);
                        $acW   = min($share, $maxC);
                        $b['current'] += ($acW * self::INV_EFF / 1000.0) * $b['eff'];
                        $totalChargeAc += $acW;
                    }
                    unset($b);

                    $genUsed[$h]    += $totalChargeAc;
                    $battChrgGen[$h] = $totalChargeAc;
                }
            }

            $unmet[$h] = max(0.0, $remaining);

            $totalUsable  = array_sum(array_column($bst, 'usable'));
            $totalCurrent = array_sum(array_column($bst, 'current'));
            $socTrace[$h] = $totalUsable > 0 ? round($totalCurrent / $totalUsable, 3) : 0.0;
        }

        // ── Night generator stats (computed from raw arrays before rounding) ──
        $nightGenKwh   = 0.0;
        $nightGenHours = 0;
        for ($h = 0; $h < 24; $h++) {
            if (!$isDaylightH[$h] && $genUsed[$h] > 0) {
                $nightGenKwh  += $genUsed[$h] / 1000.0;
                $nightGenHours++;
            }
        }
        $sunsetSoc = $sunsetH >= 0 ? ($socTrace[$sunsetH] ?? 0.0) : 0.0;

        $r2 = fn(array $a) => array_map(fn($v) => round($v, 2), $a);

        $solarUsedR   = $r2($solarUsed);
        $battChrgSolR = $r2($battChrgSolar);
        $battChrgGenR = $r2($battChrgGen);
        $battDischR   = $r2($battDischarged);
        $battRemKwR   = $r2(array_map(fn($v) => $v / 1000.0, $battRemainingW));
        $utilityR     = $r2($utilityUsed);
        $genR         = $r2($genUsed);
        $unmetR       = $r2($unmet);

        $unmetHours = array_values(array_keys(array_filter($unmetR, fn($v) => $v > 0)));
        $maxUnmetKw = count($unmetHours) > 0
            ? round(max(array_intersect_key($unmetR, array_flip($unmetHours))) / 1000.0, 3)
            : 0.0;

        $battChrgTotal = array_map(fn($s, $g) => round($s + $g, 2), $battChrgSolR, $battChrgGenR);

        // ── Stats ─────────────────────────────────────────────────────────────
        $solarKwh    = array_sum($solarUsedR)  / 1000;
        $bDischKwh   = array_sum($battDischR)   / 1000;
        $bChrgSolKwh = array_sum($battChrgSolR) / 1000;
        $bChrgGenKwh = array_sum($battChrgGenR) / 1000;
        $utilKwh     = array_sum($utilityR)     / 1000;
        $genKwh      = array_sum($genR)         / 1000;
        $unmetKwh    = array_sum($unmetR)       / 1000;
        $loadKwh     = array_sum(array_map(fn($v) => max(0.0, (float)$v) / 1000, $loadW));
        $solarGenKwh = array_sum(array_map(fn($v) => max(0.0, (float)$v) / 1000, $solarW));
        $bChrgKwh    = $bChrgSolKwh + $bChrgGenKwh;

        $solarSelfConsumption = $solarGenKwh > 0
            ? round(($solarKwh + $bChrgSolKwh) / $solarGenKwh * 100, 1)
            : 0.0;

        $sH = count(array_filter($solarUsedR));
        $uH = count(array_filter($utilityR));
        $gH = count(array_filter($genR));

        $genLoadingSum = 0.0;
        $genRunHours   = 0;
        if ($genCapW > 0) {
            foreach ($genR as $gW) {
                if ($gW > 0) { $genLoadingSum += $gW / $genCapW * 100; $genRunHours++; }
            }
        }
        $genEfficiencyAvg = $genRunHours > 0 ? round($genLoadingSum / $genRunHours, 1) : 0.0;

        return [
            'solar_used'                    => $solarUsedR,
            'battery_charged_solar'         => $battChrgSolR,
            'battery_charged_gen'           => $battChrgGenR,
            'battery_charged'               => $battChrgTotal,
            'battery_discharged'            => $battDischR,
            'battery_remaining_capacity_kw' => $battRemKwR,
            'utility_used'                  => $utilityR,
            'generator_used'                => $genR,
            'unmet'                         => $unmetR,
            'unmet_hours'                   => $unmetHours,
            'max_unmet_kw'                  => $maxUnmetKw,
            'battery_depleted_at_hour'      => $battDepletedAt,
            'battery_soc_trace'             => $socTrace,
            'sub_optimal_loading_hours'     => $subOptimalLoadingHours,
            'stats' => [
                'solar_hours'                       => $sH,
                'solar_kwh'                         => round($solarKwh, 2),
                'solar_generated_kwh'               => round($solarGenKwh, 2),
                'solar_self_consumption'            => $solarSelfConsumption,
                'battery_discharged_kwh'            => round($bDischKwh, 2),
                'battery_charged_kwh'               => round($bChrgKwh, 2),
                'battery_charged_solar_kwh'         => round($bChrgSolKwh, 2),
                'battery_charged_gen_kwh'           => round($bChrgGenKwh, 2),
                'battery_efficiency_loss_kwh'       => round($bChrgKwh - $bDischKwh, 2),
                'final_soc'                         => $socTrace[23] ?? 0.0,
                'utility_hours'                     => $uH,
                'utility_kwh'                       => round($utilKwh, 2),
                'generator_hours'                   => $gH,
                'generator_kwh'                     => round($genKwh, 2),
                'generator_efficiency_avg'          => $genEfficiencyAvg,
                'generator_daylight_hours'          => array_values(array_keys(array_filter($genDaylightFlag))),
                'generator_sub_optimal_hours'       => array_keys($subOptimalLoadingHours),
                'generator_sub_optimal_count'       => count($subOptimalLoadingHours),
                'unmet_kwh'                         => round($unmetKwh, 2),
                'total_load_kwh'                    => round($loadKwh, 2),
                // ── Look-ahead diagnostics ────────────────────────────────────
                'battery_target_soc'                => round($battTargetSoc, 3),
                'battery_target_kwh'                => round($battTargetKwh, 2),
                'night_energy_kwh'                  => round($nightEnergyKwh, 2),
                'soc_at_sunset'                     => $sunsetSoc,
                'night_generator_hours'             => $nightGenHours,
                'night_generator_kwh'               => round($nightGenKwh, 2),
                'solar_peak_hour'                   => $solarPeakH,
            ],
        ];
    }

    // ── Basic stats (no-battery path) ─────────────────────────────────────────

    private function basicStats(
        array $su, array $uu, array $gu, array $unmet, array $load, array $solar
    ): array {
        $sH = $sKwh = $uH = $uKwh = $gH = $gKwh = $unmetKwh = $loadKwh = $solarGenKwh = 0;

        for ($h = 0; $h < 24; $h++) {
            $s = $su[$h] ?? 0; $u = $uu[$h] ?? 0; $g = $gu[$h] ?? 0; $un = $unmet[$h] ?? 0;
            if ($s > 0) { $sH++; $sKwh += $s / 1000; }
            if ($u > 0) { $uH++; $uKwh += $u / 1000; }
            if ($g > 0) { $gH++; $gKwh += $g / 1000; }
            $unmetKwh    += $un / 1000;
            $loadKwh     += ($load[$h]  ?? 0) / 1000;
            $solarGenKwh += ($solar[$h] ?? 0) / 1000;
        }

        return [
            'solar_hours'            => $sH,
            'solar_kwh'              => round($sKwh, 2),
            'utility_hours'          => $uH,
            'utility_kwh'            => round($uKwh, 2),
            'generator_hours'        => $gH,
            'generator_kwh'          => round($gKwh, 2),
            'unmet_kwh'              => round($unmetKwh, 2),
            'total_load_kwh'         => round($loadKwh, 2),
            'solar_generated_kwh'    => round($solarGenKwh, 2),
            'solar_self_consumption' => $solarGenKwh > 0 ? round($sKwh / $solarGenKwh * 100, 1) : 0,
        ];
    }
}
