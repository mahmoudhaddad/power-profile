<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Collection;

/**
 * Optimized source dispatch — greedy per-hour.
 *
 * Priority order (highest to lowest benefit):
 *   1. Solar covers load directly
 *   2. Solar surplus → paired battery banks (dedicated solar system)
 *   3. Solar surplus pool → unpaired battery banks
 *   4. Battery banks discharge → remaining load
 *   5. Utility grid → remaining load
 *   6. Generator → remaining load
 *   7. Opportunistic generator charging: when generator is already running
 *      AND has spare capacity below GEN_OPTIMAL_MAX_LOAD (85%), charge batteries.
 *      Generator is burning fuel anyway; improving its load factor toward the
 *      70–85% optimal band reduces specific fuel consumption.
 *
 * Utility charging is INTENTIONALLY excluded.
 * Without Time-of-Use tariff data, charging from utility incurs:
 *   - Utility cost to charge
 *   - ~15% round-trip efficiency loss
 *   - Discharged later to displace… more utility
 * Net result is a cost increase, not savings.
 * Future: enable when ToU tariffs are added to the data model.
 */
class SourceDispatchService
{
    /**
     * Maximum generator loading fraction when opportunistically charging batteries.
     * Above 85% load, generator wear rate increases and specific fuel consumption
     * rises above the optimal band. The generator does NOT start solely to charge.
     */
    private const GEN_OPTIMAL_MAX_LOAD = 0.85;

    /**
     * Inverter/rectifier one-way efficiency for the generator→battery AC→DC path.
     * Both AC-coupled and DC-coupled topologies incur this loss when generator
     * AC power is rectified to DC for battery charging.
     * Note: solar charging uses the panel performance ratio (PR = 0.80) which
     * already accounts for DC-side losses, so INV_EFF is NOT applied there.
     */
    private const INV_EFF = 0.95;
    public function dispatch(
        array $loadW,
        array $solarW,
        float $utilityCapW,
        float $generatorCapW,
        ?Collection $batteries   = null,
        float $solarCapacityW    = 0.0,
        ?Collection $solarSystems = null
    ): array {
        $activeBatteries = $batteries?->filter(fn($b) => $b->is_active && $b->usable_capacity_kwh > 0);

        if ($activeBatteries && $activeBatteries->isNotEmpty()) {
            $result = $this->dispatchOptimized(
                $loadW, $solarW, $utilityCapW, $generatorCapW,
                $activeBatteries, $solarCapacityW, $solarSystems
            );
            $result['has_battery_storage'] = true;
            return $result;
        }

        return $this->dispatchBasic($loadW, $solarW, $utilityCapW, $generatorCapW);
    }

    // ── Basic dispatch (no batteries) ────────────────────────────────────────

    private function dispatchBasic(
        array $loadW, array $solarW, float $utilityCapW, float $generatorCapW
    ): array {
        $solarUsed = $utilityUsed = $generatorUsed = $unmet = [];

        for ($h = 0; $h < 24; $h++) {
            $d = max(0.0, (float) ($loadW[$h]  ?? 0));
            $s = max(0.0, (float) ($solarW[$h] ?? 0));

            $sU = min($s, $d);          $d -= $sU;
            $uU = min($utilityCapW, $d); $d -= $uU;
            $gU = min($generatorCapW, $d); $d -= $gU;

            $solarUsed[$h]     = round($sU, 2);
            $utilityUsed[$h]   = round($uU, 2);
            $generatorUsed[$h] = round($gU, 2);
            $unmet[$h]         = round(max(0.0, $d), 2);
        }

        return [
            'solar_used'          => $solarUsed,
            'utility_used'        => $utilityUsed,
            'generator_used'      => $generatorUsed,
            'unmet'               => $unmet,
            'has_battery_storage' => false,
            'stats'               => $this->basicStats($solarUsed, $utilityUsed, $generatorUsed, $unmet, $loadW, $solarW),
        ];
    }

    // ── Optimized dispatch (batteries + optional named solar systems) ─────────

    private function dispatchOptimized(
        array $loadW,
        array $solarW,
        float $utilityCapW,
        float $genCapW,
        Collection $batteries,
        float $solarCapacityW,
        ?Collection $solarSystems
    ): array {
        // ── Build per-battery mutable state ──────────────────────────────────
        $bst = [];
        foreach ($batteries as $b) {
            $bst[$b->id] = [
                'usable'     => (float) $b->usable_capacity_kwh,
                'current'    => (float) $b->usable_capacity_kwh * max(0.0, min(1.0, (float) $b->current_soc)),
                'charge_kw'  => (float) $b->max_charge_power_kw,
                'disch_kw'   => (float) $b->max_discharge_power_kw,
                'eff'        => sqrt(max(0.5, (float) $b->round_trip_efficiency)),
                'sys_id'     => $b->solar_system_id, // null = shared pool
            ];
        }

        // ── Index: solar_system_id → [battery_ids paired to it] ─────────────
        $sysToBank = [];
        $unpairedIds = [];
        foreach ($bst as $bid => $b) {
            if ($b['sys_id']) {
                $sysToBank[$b['sys_id']][] = $bid;
            } else {
                $unpairedIds[] = $bid;
            }
        }

        // ── Solar system capacity ratios ──────────────────────────────────────
        // Each named system's share of the total solar profile
        $totalSolarCapW = max(1.0, $solarCapacityW);
        $sysRatios = [];
        if ($solarSystems && $solarSystems->isNotEmpty()) {
            foreach ($solarSystems as $sys) {
                $sysRatios[$sys->id] = ($sys->capacity_kw * 1000.0) / $totalSolarCapW;
            }
        }

        // ── Output arrays ─────────────────────────────────────────────────────
        $solarUsed        = array_fill(0, 24, 0.0);
        $battChrgSolar    = array_fill(0, 24, 0.0); // charged from solar surplus
        $battChrgGen      = array_fill(0, 24, 0.0); // charged from generator spare
        $battDischarged   = array_fill(0, 24, 0.0);
        $utilityUsed      = array_fill(0, 24, 0.0);
        $genUsed          = array_fill(0, 24, 0.0);
        $unmet            = array_fill(0, 24, 0.0);
        $socTrace         = array_fill(0, 24, 0.0);

        for ($h = 0; $h < 24; $h++) {
            $demand    = max(0.0, (float) ($loadW[$h]  ?? 0));
            $solarTot  = max(0.0, (float) ($solarW[$h] ?? 0));
            $sharedSolar = $solarTot; // will be reduced as paired systems claim their slices

            // ── STEP 1: Paired solar systems charge their exclusive batteries ───
            // Each system's proportional output goes to its bank first.
            // Whatever the bank can't absorb (full/rate-limited) returns to shared pool.
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
                // Only the consumed portion is "claimed" from shared pool
                $sharedSolar -= $totalCharged;
                $battChrgSolar[$h] += $totalCharged;
            }
            $sharedSolar = max(0.0, $sharedSolar);

            // ── STEP 2: Shared solar covers load ─────────────────────────────
            $solarUsed[$h] = min($sharedSolar, $demand);
            $surplus       = $sharedSolar - $solarUsed[$h];
            $remaining     = $demand       - $solarUsed[$h];

            // ── STEP 3: Surplus shared solar charges unpaired banks ───────────
            if ($surplus > 0 && !empty($unpairedIds)) {
                $totalHead = 0.0;
                foreach ($unpairedIds as $bid) {
                    $totalHead += max(0.0, $bst[$bid]['usable'] - $bst[$bid]['current']) * 1000.0;
                }
                if ($totalHead > 0) {
                    $maxPoolCharge = min($surplus,
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

            // ── STEP 4: All banks discharge to cover remaining demand ─────────
            if ($remaining > 0) {
                $totalCurrent = array_sum(array_column($bst, 'current'));
                if ($totalCurrent > 0) {
                    $maxDischW  = min(
                        array_sum(array_column($bst, 'disch_kw')) * 1000.0,
                        $totalCurrent * 1000.0
                    );
                    $dischargeW = min($remaining, $maxDischW);

                    foreach ($bst as $bid => &$b) {
                        if ($b['current'] <= 0 || $totalCurrent <= 0) continue;
                        $share  = $dischargeW * ($b['current'] / $totalCurrent);
                        $maxD   = min($b['disch_kw'] * 1000.0, $b['current'] * 1000.0);
                        $actual = min($share, $maxD);
                        $b['current'] -= ($actual / 1000.0) / max(0.5, $b['eff']);
                        $b['current']  = max(0.0, $b['current']);
                    }
                    unset($b);

                    $battDischarged[$h] = $dischargeW;
                    $remaining         -= $dischargeW;
                }
            }

            // ── STEP 5: Utility ───────────────────────────────────────────────
            $utilityUsed[$h] = min($utilityCapW, $remaining);
            $remaining      -= $utilityUsed[$h];

            // ── STEP 6: Generator ─────────────────────────────────────────────
            $genUsed[$h] = min($genCapW, $remaining);
            $remaining  -= $genUsed[$h];

            // ── STEP 7: Opportunistic generator charging ──────────────────────
            // Only when generator is ALREADY running for load coverage.
            // Capped at GEN_OPTIMAL_MAX_LOAD (85%) to stay in the efficient band.
            // Generator does NOT start solely to charge — that wastes fuel.
            if ($genUsed[$h] > 0 && $genCapW > 0) {
                $genMaxForCharging = $genCapW * self::GEN_OPTIMAL_MAX_LOAD;
                $spareGenW = max(0.0, $genMaxForCharging - $genUsed[$h]);

                if ($spareGenW > 0) {
                    $totalHead = 0.0;
                    foreach ($bst as $b) {
                        $totalHead += max(0.0, $b['usable'] - $b['current']) * 1000.0;
                    }
                    if ($totalHead > 0) {
                        $totalChargeKwAll = array_sum(array_column($bst, 'charge_kw'));

                        // AC watts drawn from generator (headroom is DC → divide by INV_EFF)
                        $maxGenChargeAc = min(
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
                            // Apply INV_EFF (AC→DC) then battery one-way efficiency
                            $b['current'] += ($acW * self::INV_EFF / 1000.0) * $b['eff'];
                            $totalChargeAc += $acW;
                        }
                        unset($b);

                        $genUsed[$h]     += $totalChargeAc; // generator draws more AC for charging
                        $battChrgGen[$h]  = $totalChargeAc; // track AC watts drawn (pre-conversion)
                    }
                }
            }

            $unmet[$h] = max(0.0, $remaining);

            // SOC trace — capacity-weighted average across all banks
            $totalUsable  = array_sum(array_column($bst, 'usable'));
            $totalCurrent = array_sum(array_column($bst, 'current'));
            $socTrace[$h] = $totalUsable > 0 ? round($totalCurrent / $totalUsable, 3) : 0.0;
        }

        $r2 = fn(array $a) => array_map(fn($v) => round($v, 2), $a);

        $solarUsedR  = $r2($solarUsed);
        $battChrgSolR= $r2($battChrgSolar);
        $battChrgGenR= $r2($battChrgGen);
        $battDischR  = $r2($battDischarged);
        $utilityR    = $r2($utilityUsed);
        $genR        = $r2($genUsed);
        $unmetR      = $r2($unmet);

        $battChrgTotal = array_map(fn($s, $g) => round($s + $g, 2), $battChrgSolR, $battChrgGenR);

        // ── Stats ─────────────────────────────────────────────────────────────
        $solarKwh    = array_sum($solarUsedR)  / 1000;
        $bDischKwh   = array_sum($battDischR)   / 1000;
        $bChrgSolKwh = array_sum($battChrgSolR) / 1000;
        $bChrgGenKwh = array_sum($battChrgGenR) / 1000;
        $utilKwh     = array_sum($utilityR)     / 1000;
        $genKwh      = array_sum($genR)         / 1000;
        $unmetKwh    = array_sum($unmetR)       / 1000;
        $loadKwh     = array_sum(array_map(fn($v) => max(0.0, (float) $v) / 1000, $loadW));
        $solarGenKwh = array_sum(array_map(fn($v) => max(0.0, (float) $v) / 1000, $solarW));
        $bChrgKwh    = $bChrgSolKwh + $bChrgGenKwh;

        $solarSelfConsumption = $solarGenKwh > 0
            ? round(($solarKwh + $bChrgSolKwh) / $solarGenKwh * 100, 1)
            : 0.0;

        $sH = count(array_filter($solarUsedR));
        $uH = count(array_filter($utilityR));
        $gH = count(array_filter($genR));

        // Average generator loading % across hours it was running
        // (genUsed already includes the extra AC drawn for battery charging)
        $genLoadingSum = 0.0;
        $genRunHours   = 0;
        if ($genCapW > 0) {
            foreach ($genR as $gW) {
                if ($gW > 0) { $genLoadingSum += $gW / $genCapW * 100; $genRunHours++; }
            }
        }
        $genEfficiencyAvg = $genRunHours > 0 ? round($genLoadingSum / $genRunHours, 1) : 0.0;

        return [
            'solar_used'              => $solarUsedR,
            'battery_charged_solar'   => $battChrgSolR,
            'battery_charged_gen'     => $battChrgGenR,
            'battery_charged'         => $battChrgTotal,
            'battery_discharged'      => $battDischR,
            'utility_used'            => $utilityR,
            'generator_used'          => $genR,
            'unmet'                   => $unmetR,
            'battery_soc_trace'       => $socTrace,
            'stats' => [
                'solar_hours'                   => $sH,
                'solar_kwh'                     => round($solarKwh, 2),
                'solar_generated_kwh'           => round($solarGenKwh, 2),
                'solar_self_consumption'        => $solarSelfConsumption,
                'battery_discharged_kwh'        => round($bDischKwh, 2),
                'battery_charged_kwh'           => round($bChrgKwh, 2),
                'battery_charged_solar_kwh'     => round($bChrgSolKwh, 2),
                'battery_charged_gen_kwh'       => round($bChrgGenKwh, 2),
                'battery_efficiency_loss_kwh'   => round($bChrgKwh - $bDischKwh, 2),
                'final_soc'                     => $socTrace[23] ?? 0.0,
                'utility_hours'                 => $uH,
                'utility_kwh'                   => round($utilKwh, 2),
                'generator_hours'               => $gH,
                'generator_kwh'                 => round($genKwh, 2),
                'generator_efficiency_avg'      => $genEfficiencyAvg,
                'unmet_kwh'                     => round($unmetKwh, 2),
                'total_load_kwh'                => round($loadKwh, 2),
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
