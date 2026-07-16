<?php

namespace App\Services;

/**
 * Priority-based demand-side load shedding / restoration.
 *
 * Operates on pre-built component slots (see ScheduleController::buildComponentSlots)
 * and a 24-element hourly raw-unmet array produced by a pre-pass of SourceDispatchService
 * on the original, unshed load profile.  This makes shedding energy-aware: it responds to
 * the battery's real state-of-charge trajectory rather than a peak-discharge-power estimate.
 *
 * Shedding order (per deficit hour):
 *   1. Shift  shiftable loads to a surplus hour within their scheduling window
 *   2. Curtail curtailable loads down to curtail_min_pct (largest reduction first)
 *   3. Shed   Normal   loads (largest first)
 *   4. Shed   Essential loads (largest first, only if Normal alone insufficient)
 *   5. Never  shed Critical loads → residual reported as critical_unmet_kwh
 *
 * Restoration (reverse order, with hysteresis):
 *   A shed load is not restored in hour H unless hour H-1 had a remaining deficit of 0.
 *   Restoration order: Essential → Normal → Curtailable.
 *
 * Cost-Priority mode (activated by non-empty $costOpts):
 *   Withholds restoration of normal/curtailable loads when restoring would place the
 *   generator in an inefficient low-load zone (avg cost/kWh > 1.2× optimal).
 *   NEVER proactively sheds currently-served load — shedding is driven solely by
 *   genuine rawUnmetW deficit.  Essential loads always bypass the cost gate.
 */
class LoadSheddingService
{
    /** Tie-break rule when multiple loads of the same tier could be shed/restored. */
    private const TIEBREAK = 'largest_first'; // ⚠ tunable (only 'largest_first' implemented)

    /**
     * IEC 60034-1 continuous-duty ceiling: generator must not be loaded above 85 % of
     * rated for sustained periods.  Restoration that would breach this is always blocked,
     * regardless of mode.
     */
    private const MAX_GEN_FRACTION = 0.85;

    /**
     * Cost-Priority mode: fuel-cost-ratio gate.
     * Restoration of normal/curtailable loads is withheld when the estimated average
     * fuel cost per kWh at the post-restoration generator load exceeds this multiple of
     * the optimal (full-load) cost per kWh.
     *
     * k = 1.2 → breakeven load fraction f* = F₀ / (0.2 × F_rated + F₀)
     *
     * For a typical diesel generator with F₀/F_rated ≈ 0.30:
     *   f* = 0.30 × F_rated / (0.2 × F_rated + 0.30 × F_rated) = 0.30 / 0.50 = 0.60 (60 %)
     *
     * Below f* the generator is in its inefficient light-load zone; above f* it is in
     * its efficient 60–85 % operating band.  30 % is the wet-stack FLOOR, not a ceiling
     * for Cost-Priority — generators must not run BELOW 30 % (Caterpillar guidance).
     */
    private const COST_PRIORITY_THRESHOLD_FACTOR = 1.2;

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Run the shedding/restoration algorithm.
     *
     * @param  array  $slots       Component slots built by ScheduleController::buildComponentSlots().
     *                             Each slot: peak_w, priority, load_flexibility, active_hours[24],
     *                             curtail_min_pct, earliest_start, latest_end, required_run_h, label.
     * @param  array  $rawUnmetW   24-element array of per-hour unmet watts from a pre-pass of
     *                             SourceDispatchService on the original (unshed) load.  Values > 0
     *                             signal a genuine energy deficit that shedding must address.
     * @param  array  $shiftCapW   Optional 24-element array of solar+grid-only supply caps (watts).
     *                             Used only as headroom guard when choosing shift targets, so loads
     *                             are not moved to dark generator-backed hours.  When empty, no
     *                             headroom constraint is applied beyond rawUnmetW = 0.
     *
     * @return array {
     *   adjusted_load_w:      float[24]  — load profile after shedding/shifting,
     *   shed_curtailable_kwh: float,
     *   shed_normal_kwh:      float,
     *   shed_essential_kwh:   float,
     *   critical_unmet_kwh:   float,     — residual after shedding all non-critical loads,
     *   per_load_shed_list:   array,     — each: [slot_id, label, hour, action, kwh],
     *   hourly_shed:          array[24]  — per-hour: [deficit_w, critical_unmet_w?],
     * }
     */
    /**
     * @param  array  $costOpts  When non-empty, activates Cost-Priority restoration mode.
     *                           Required keys: gen_cap_w (W), f_rated_lph, f_no_load_lph,
     *                           fuel_price, solar_w (float[24]), util_cap_w (W).
     *                           Restoration of normal/curtailable loads is withheld when the
     *                           estimated post-restoration generator load would be either:
     *                             (a) above MAX_GEN_FRACTION (85 % — IEC continuous-duty), or
     *                             (b) so low that avg fuel cost/kWh > 1.2 × optimal cost/kWh
     *                                 (i.e. the generator would run in its inefficient zone).
     *                           Shedding is never affected — Cost-Priority ONLY gates restoration.
     *                           Essential loads always bypass the cost gate (but not the 85 % ceiling).
     *                           When empty (default), Service-Priority mode is used.
     */
    public function shed(array $slots, array $rawUnmetW, array $shiftCapW = [], array $costOpts = []): array
    {
        $n = count($slots);

        // Per-slot mutable state
        $shedState           = array_fill(0, $n, 'active'); // 'active'|'curtailed'|'shed'
        $curtailedReductionW = array_fill(0, $n, 0.0);      // watts removed by curtailment

        // Build initial effective load (sum of all active slots per hour)
        $effectiveLoadW = array_fill(0, 24, 0.0);
        foreach ($slots as $slot) {
            for ($h = 0; $h < 24; $h++) {
                if ($slot['active_hours'][$h]) {
                    $effectiveLoadW[$h] += $slot['peak_w'];
                }
            }
        }

        // Snapshot of the original (pre-shed) hourly load — used for deficit accounting
        $initialLoadW = $effectiveLoadW;

        $shedCurtailableKwh = 0.0;
        $shedNormalKwh      = 0.0;
        $shedEssentialKwh   = 0.0;
        $criticalUnmetKwh   = 0.0;
        $perLoadShedList    = [];
        $hourlyDetail       = array_fill(0, 24, null);

        $surplusLastHour = false;

        for ($h = 0; $h < 24; $h++) {
            // ── Restoration phase (triggered by previous hour's surplus) ──────
            if ($surplusLastHour) {
                $this->tryRestore($h, $slots, $shedState, $curtailedReductionW, $effectiveLoadW, $rawUnmetW, $initialLoadW, $costOpts);
            }

            // Deficit = raw dispatch unmet minus load already shed at this hour.
            // This is energy-aware: rawUnmetW came from a real SOC-tracked dispatch pass.
            $loadShedSoFar = max(0.0, $initialLoadW[$h] - $effectiveLoadW[$h]);
            $deficit = max(0.0, (float) ($rawUnmetW[$h] ?? 0.0) - $loadShedSoFar);

            // Surplus trigger: deficit reduced to zero (no hysteresis margin needed because
            // rawUnmetW already encodes the real headroom from the dispatch pre-pass).
            $surplusLastHour = $deficit <= 0;

            if ($deficit <= 0) {
                $hourlyDetail[$h] = ['deficit_w' => 0.0];
                continue;
            }

            $hourlyDetail[$h] = ['deficit_w' => round($deficit, 2)];

            // ── Step 1: Shift shiftable loads ─────────────────────────────────
            $shiftables = $this->activeSlotsAtHour($slots, $shedState, $h, null, 'shiftable');
            $this->sortByPeakDesc($shiftables, $slots);

            foreach ($shiftables as $i) {
                if ($deficit <= 0) break;
                $tgt = $this->findShiftTarget($slots[$i], $h, $effectiveLoadW, $shiftCapW, $rawUnmetW);
                if ($tgt !== null) {
                    $slots[$i]['active_hours'][$h]   = false;
                    $slots[$i]['active_hours'][$tgt] = true;
                    $effectiveLoadW[$h]   -= $slots[$i]['peak_w'];
                    $effectiveLoadW[$tgt] += $slots[$i]['peak_w'];
                    $deficit -= $slots[$i]['peak_w'];
                    $perLoadShedList[] = [
                        'slot_id' => $i,
                        'label'   => $slots[$i]['label'],
                        'hour'    => $h,
                        'action'  => "shifted_to_h{$tgt}",
                        'kwh'     => round($slots[$i]['peak_w'] / 1000.0, 4),
                    ];
                }
            }

            if ($deficit <= 0) continue;

            // ── Step 2: Curtail curtailable loads ──────────────────────────────
            $curtailables = $this->activeSlotsAtHour($slots, $shedState, $h, null, 'curtailable');
            $this->sortByPeakDesc($curtailables, $slots);

            foreach ($curtailables as $i) {
                if ($deficit <= 0) break;
                $currentW   = $slots[$i]['peak_w'] - $curtailedReductionW[$i];
                $minW       = $slots[$i]['peak_w'] * ($slots[$i]['curtail_min_pct'] / 100.0);
                $maxCurtail = max(0.0, $currentW - $minW);
                if ($maxCurtail <= 0) continue;

                $curtailAmt = min($maxCurtail, $deficit);
                $curtailedReductionW[$i] += $curtailAmt;
                // Remove curtailed watts from this hour and all remaining active hours
                for ($f = $h; $f < 24; $f++) {
                    if ($slots[$i]['active_hours'][$f]) {
                        $effectiveLoadW[$f] -= $curtailAmt;
                    }
                }
                $deficit -= $curtailAmt;
                $shedCurtailableKwh += $curtailAmt / 1000.0;
                $shedState[$i] = 'curtailed';
                $perLoadShedList[] = [
                    'slot_id' => $i,
                    'label'   => $slots[$i]['label'],
                    'hour'    => $h,
                    'action'  => 'curtailed',
                    'kwh'     => round($curtailAmt / 1000.0, 4),
                ];
            }

            if ($deficit <= 0) continue;

            // ── Step 3: Shed Normal loads ──────────────────────────────────────
            $normals = $this->activeSlotsAtHour($slots, $shedState, $h, 'normal', null);
            $this->sortByPeakDesc($normals, $slots);

            foreach ($normals as $i) {
                if ($deficit <= 0) break;
                $removed = $this->shedSlotFromHour($i, $h, $slots, $shedState, $curtailedReductionW, $effectiveLoadW);
                $deficit -= $removed;
                $shedNormalKwh += $removed / 1000.0;
                $perLoadShedList[] = [
                    'slot_id' => $i,
                    'label'   => $slots[$i]['label'],
                    'hour'    => $h,
                    'action'  => 'shed_normal',
                    'kwh'     => round($removed / 1000.0, 4),
                ];
            }

            if ($deficit <= 0) continue;

            // ── Step 4: Shed Essential loads ───────────────────────────────────
            $essentials = $this->activeSlotsAtHour($slots, $shedState, $h, 'essential', null);
            $this->sortByPeakDesc($essentials, $slots);

            foreach ($essentials as $i) {
                if ($deficit <= 0) break;
                $removed = $this->shedSlotFromHour($i, $h, $slots, $shedState, $curtailedReductionW, $effectiveLoadW);
                $deficit -= $removed;
                $shedEssentialKwh += $removed / 1000.0;
                $perLoadShedList[] = [
                    'slot_id' => $i,
                    'label'   => $slots[$i]['label'],
                    'hour'    => $h,
                    'action'  => 'shed_essential',
                    'kwh'     => round($removed / 1000.0, 4),
                ];
            }

            // ── Step 5: Critical loads → unmet (never auto-shed) ──────────────
            if ($deficit > 0) {
                $criticalUnmetKwh += $deficit / 1000.0;
                $hourlyDetail[$h]['critical_unmet_w'] = round($deficit, 2);
            }
        }

        return [
            'adjusted_load_w'      => array_map(fn($v) => round(max(0.0, $v), 2), $effectiveLoadW),
            'shed_curtailable_kwh' => round($shedCurtailableKwh, 3),
            'shed_normal_kwh'      => round($shedNormalKwh, 3),
            'shed_essential_kwh'   => round($shedEssentialKwh, 3),
            'critical_unmet_kwh'   => round($criticalUnmetKwh, 3),
            'per_load_shed_list'   => $perLoadShedList,
            'hourly_shed'          => $hourlyDetail,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Shed slot i starting at hour h: remove its current effective contribution
     * from all remaining active hours, mark as 'shed'.
     * Returns the watts actually removed (= peak_w − curtailment).
     */
    private function shedSlotFromHour(
        int $i, int $h,
        array $slots,
        array &$shedState,
        array &$curtailedReductionW,
        array &$effectiveLoadW
    ): float {
        $currentW = $slots[$i]['peak_w'] - $curtailedReductionW[$i];
        if ($currentW <= 0) {
            $shedState[$i] = 'shed';
            return 0.0;
        }
        for ($f = $h; $f < 24; $f++) {
            if ($slots[$i]['active_hours'][$f]) {
                $effectiveLoadW[$f] -= $currentW;
            }
        }
        $shedState[$i] = 'shed';
        return $currentW;
    }

    /**
     * Attempt to restore shed/curtailed loads at hour h.
     * Order: Essential → Normal → Curtailable (reverse of shedding order).
     * Within each tier: largest first.
     * A load is only restored if its wattage fits within the headroom still available
     * above the raw-dispatch deficit for this hour.
     */
    private function tryRestore(
        int $h,
        array $slots,
        array &$shedState,
        array &$curtailedReductionW,
        array &$effectiveLoadW,
        array $rawUnmetW,
        array $initialLoadW,
        array $costOpts = []
    ): void {
        foreach (['essential', 'normal', 'curtailable'] as $tier) {
            $candidates = [];

            foreach ($shedState as $i => $state) {
                if ($state === 'active') continue;
                if (!$slots[$i]['active_hours'][$h]) continue;

                $match = match ($tier) {
                    'essential'   => $state === 'shed' && $slots[$i]['priority'] === 'essential',
                    'normal'      => $state === 'shed' && $slots[$i]['priority'] === 'normal',
                    'curtailable' => $state === 'curtailed',
                    default       => false,
                };
                if (!$match) continue;

                $restoreW = $state === 'shed'
                    ? $slots[$i]['peak_w']
                    : $curtailedReductionW[$i];

                if ($restoreW <= 0) continue;
                $candidates[] = ['i' => $i, 'restore_w' => $restoreW];
            }

            usort($candidates, fn($a, $b) => $b['restore_w'] <=> $a['restore_w']);

            foreach ($candidates as $c) {
                $i        = $c['i'];
                $restoreW = $c['restore_w'];

                // Available headroom = load already shed at this hour minus raw dispatch deficit.
                // Restoring is only safe if restoreW does not push us back into deficit.
                $loadShedAtH        = max(0.0, $initialLoadW[$h] - $effectiveLoadW[$h]);
                $availableToRestore = max(0.0, $loadShedAtH - (float) ($rawUnmetW[$h] ?? 0.0));

                if ($restoreW > $availableToRestore) {
                    continue;
                }

                // ── Cost-Priority gate (normal/curtailable only; essential always restores) ──
                if (!empty($costOpts) && $tier !== 'essential') {
                    $genCapW = (float) ($costOpts['gen_cap_w'] ?? 0.0);
                    if ($genCapW > 0.0) {
                        $estGenLoad = max(0.0,
                            $effectiveLoadW[$h] + $restoreW
                            - (float) ($costOpts['solar_w'][$h] ?? 0.0)
                            - (float) ($costOpts['util_cap_w']  ?? 0.0)
                        );

                        // Gate 1: IEC 60034-1 continuous-duty ceiling — never exceed 85 % of rated.
                        if ($estGenLoad > self::MAX_GEN_FRACTION * $genCapW) {
                            continue;
                        }

                        // Gate 2: fuel-cost-ratio gate — withhold restoration when the generator
                        // would run so lightly that avg cost/kWh > 1.2× optimal (full-load) cost/kWh.
                        // Breakeven fraction: f* = F₀ / (0.2×F_rated + F₀) ≈ 60 % for a typical diesel.
                        // Below f* is the inefficient light-load zone; above is the efficient 60–85 % band.
                        // 30 % is the wet-stack FLOOR (must not run BELOW that), not a ceiling here.
                        $fRated = (float) ($costOpts['f_rated_lph']   ?? 0.0);
                        $f0     = (float) ($costOpts['f_no_load_lph'] ?? 0.0);
                        $price  = (float) ($costOpts['fuel_price']    ?? 0.0);

                        if ($estGenLoad > 0.0 && $fRated > 0.0 && $price > 0.0) {
                            // F(P) = F₀ + (F_rated − F₀) × (P / P_rated)  [linear IEC approximation]
                            $fEst    = $f0 + ($fRated - $f0) * ($estGenLoad / $genCapW);
                            $avgCost = $fEst   * $price / ($estGenLoad / 1000.0); // $/kWh at est load
                            $optCost = $fRated * $price / ($genCapW     / 1000.0); // $/kWh at rated load

                            if ($avgCost > self::COST_PRIORITY_THRESHOLD_FACTOR * $optCost) {
                                continue;
                            }
                        }
                    }
                }

                for ($f = $h; $f < 24; $f++) {
                    if ($slots[$i]['active_hours'][$f]) {
                        $effectiveLoadW[$f] += $restoreW;
                    }
                }

                $curtailedReductionW[$i] = 0.0;
                $shedState[$i]           = 'active';
            }
        }
    }

    /**
     * Find a surplus hour within the shiftable slot's scheduling window that can
     * absorb the load without creating a new deficit.
     *
     * Target hour must satisfy:
     *   1. rawUnmetW[h] = 0  (no existing deficit in the raw dispatch pass)
     *   2. shiftCapW[h] − effectiveLoadW[h] >= slot.peak_w  (renewable+grid headroom, when provided)
     */
    private function findShiftTarget(
        array $slot,
        int $currentHour,
        array $effectiveLoadW,
        array $shiftCapW,
        array $rawUnmetW
    ): ?int {
        $earliest = max(0,  $slot['earliest_start'] ?? 0);
        $latest   = min(24, $slot['latest_end']      ?? 24);

        $bestHour    = null;
        $bestSurplus = -PHP_FLOAT_MAX;

        for ($h = $earliest; $h < $latest; $h++) {
            if ($h === $currentHour)        continue;
            if ($slot['active_hours'][$h])  continue; // already scheduled here

            // Only shift to hours the real dispatch could serve without deficit
            if (($rawUnmetW[$h] ?? 0.0) > 0.0) continue;

            // When shiftCapW is provided, enforce renewable+grid headroom so loads
            // are not moved to dark generator-backed hours
            if (!empty($shiftCapW)) {
                $headroom = $shiftCapW[$h] - $effectiveLoadW[$h] - $slot['peak_w'];
                if ($headroom < 0) continue;
                $avail = $shiftCapW[$h] - $effectiveLoadW[$h];
            } else {
                $avail = PHP_FLOAT_MAX;
            }

            if ($avail > $bestSurplus) {
                $bestSurplus = $avail;
                $bestHour    = $h;
            }
        }

        return $bestHour;
    }

    /**
     * Return indices of slots that are:
     *  - active at $hour (active_hours[$hour] === true)
     *  - not shed ($shedState[$i] !== 'shed')
     *  - matching optional $priority filter
     *  - matching optional $flexibility filter
     */
    private function activeSlotsAtHour(
        array $slots,
        array $shedState,
        int   $hour,
        ?string $priority,
        ?string $flexibility
    ): array {
        $out = [];
        foreach ($slots as $i => $slot) {
            if ($shedState[$i] === 'shed')     continue;
            if (!$slot['active_hours'][$hour]) continue;
            if ($slot['priority'] === 'critical') continue; // never candidate for shedding
            if ($priority    !== null && $slot['priority']         !== $priority)    continue;
            if ($flexibility !== null && $slot['load_flexibility'] !== $flexibility) continue;
            $out[] = $i;
        }
        return $out;
    }

    /**
     * Sort slot-index array by peak_w descending (largest first).
     */
    private function sortByPeakDesc(array &$indices, array $slots): void
    {
        usort($indices, fn($a, $b) => $slots[$b]['peak_w'] <=> $slots[$a]['peak_w']);
    }
}
