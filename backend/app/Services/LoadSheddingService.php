<?php

namespace App\Services;

/**
 * Priority-based demand-side load shedding / restoration.
 *
 * Operates on pre-built component slots (see ScheduleController::buildComponentSlots)
 * and a 24-element hourly supply-capacity array.  The existing SourceDispatchService
 * is never touched — this service modifies the load profile BEFORE dispatch.
 *
 * Shedding order (per deficit hour):
 *   1. Shift  shiftable loads to a surplus hour within their scheduling window
 *   2. Curtail curtailable loads down to curtail_min_pct (largest reduction first)
 *   3. Shed   Normal   loads (largest first)
 *   4. Shed   Essential loads (largest first, only if Normal alone insufficient)
 *   5. Never  shed Critical loads → residual reported as critical_unmet_kwh
 *
 * Restoration (reverse order, with hysteresis):
 *   A shed load is not restored in hour H unless hour H-1 had supply >
 *   demand × (1 + RESTORE_MARGIN).  Restoration order: Essential → Normal → Curtailable.
 */
class LoadSheddingService
{
    /** Supply-to-demand ratio above which a surplus hour qualifies as the
     *  hysteresis trigger for restoration.  E.g. 0.05 = 5 % headroom required. */
    private const RESTORE_MARGIN = 0.05; // ⚠ tunable

    /** Tie-break rule when multiple loads of the same tier could be shed/restored. */
    private const TIEBREAK = 'largest_first'; // ⚠ tunable (only 'largest_first' implemented)

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Run the shedding/restoration algorithm.
     *
     * @param  array  $slots       Component slots built by ScheduleController::buildComponentSlots().
     *                             Each slot: peak_w, priority, load_flexibility, active_hours[24],
     *                             curtail_min_pct, earliest_start, latest_end, required_run_h, label.
     * @param  array  $supplyCapW  24-element array of maximum available supply (watts) per hour.
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
    public function shed(array $slots, array $supplyCapW, array $shiftCapW = []): array
    {
        $n = count($slots);

        // Per-slot mutable state
        $shedState          = array_fill(0, $n, 'active'); // 'active'|'curtailed'|'shed'
        $curtailedReductionW = array_fill(0, $n, 0.0);     // watts removed by curtailment

        // Build initial effective load (sum of all active slots per hour)
        $effectiveLoadW = array_fill(0, 24, 0.0);
        foreach ($slots as $i => $slot) {
            for ($h = 0; $h < 24; $h++) {
                if ($slot['active_hours'][$h]) {
                    $effectiveLoadW[$h] += $slot['peak_w'];
                }
            }
        }

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
                $this->tryRestore($h, $slots, $shedState, $curtailedReductionW, $effectiveLoadW, $supplyCapW);
            }

            $deficit = max(0.0, $effectiveLoadW[$h] - $supplyCapW[$h]);

            // Evaluate surplus AFTER any restoration, for next iteration's hysteresis
            $surplusLastHour = $deficit <= 0
                && ($effectiveLoadW[$h] <= 0 || $supplyCapW[$h] >= $effectiveLoadW[$h] * (1.0 + self::RESTORE_MARGIN));

            if ($deficit <= 0) {
                $hourlyDetail[$h] = ['deficit_w' => 0.0];
                continue;
            }

            $hourlyDetail[$h] = ['deficit_w' => round($deficit, 2)];

            // ── Step 1: Shift shiftable loads ─────────────────────────────────
            $shiftables = $this->activeSlotsAtHour($slots, $shedState, $h, null, 'shiftable');
            $this->sortByPeakDesc($shiftables, $slots);

            // Use renewable-only cap for shift targets so generator capacity at night
            // is never the reason a load gets moved to an off-peak dark hour.
            $capForShift = empty($shiftCapW) ? $supplyCapW : $shiftCapW;
            foreach ($shiftables as $i) {
                if ($deficit <= 0) break;
                $tgt = $this->findShiftTarget($slots[$i], $h, $effectiveLoadW, $capForShift);
                if ($tgt !== null) {
                    $slots[$i]['active_hours'][$h] = false;
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
            'adjusted_load_w'       => array_map(fn($v) => round(max(0.0, $v), 2), $effectiveLoadW),
            'shed_curtailable_kwh'  => round($shedCurtailableKwh, 3),
            'shed_normal_kwh'       => round($shedNormalKwh, 3),
            'shed_essential_kwh'    => round($shedEssentialKwh, 3),
            'critical_unmet_kwh'    => round($criticalUnmetKwh, 3),
            'per_load_shed_list'    => $perLoadShedList,
            'hourly_shed'           => $hourlyDetail,
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
     * A load is only restored if it fits within available supply headroom at hour h.
     */
    private function tryRestore(
        int $h,
        array $slots,
        array &$shedState,
        array &$curtailedReductionW,
        array &$effectiveLoadW,
        array $supplyCapW
    ): void {
        // Iterate tiers in reverse-of-shedding order
        foreach (['essential', 'normal', 'curtailable'] as $tier) {
            $candidates = [];

            foreach ($shedState as $i => $state) {
                if ($state === 'active') continue;
                if (!$slots[$i]['active_hours'][$h]) continue; // not scheduled this hour

                $match = match ($tier) {
                    'essential'   => $state === 'shed' && $slots[$i]['priority'] === 'essential',
                    'normal'      => $state === 'shed' && $slots[$i]['priority'] === 'normal',
                    'curtailable' => $state === 'curtailed',
                    default       => false,
                };
                if (!$match) continue;

                // Watts to add back:
                //   shed slot   → full peak_w (including previously curtailed portion)
                //   curtailed   → just the curtailed reduction
                $restoreW = $state === 'shed'
                    ? $slots[$i]['peak_w']
                    : $curtailedReductionW[$i];

                if ($restoreW <= 0) continue;
                $candidates[] = ['i' => $i, 'restore_w' => $restoreW];
            }

            // Largest restore-watts first
            usort($candidates, fn($a, $b) => $b['restore_w'] <=> $a['restore_w']);

            foreach ($candidates as $c) {
                $i        = $c['i'];
                $restoreW = $c['restore_w'];

                // Only restore if the additional demand fits within current supply
                if ($effectiveLoadW[$h] + $restoreW > $supplyCapW[$h]) {
                    continue;
                }

                // Add restored watts to this hour and all remaining active hours
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
     * absorb the load, excluding the current deficit hour.
     * Returns null if no feasible target exists.
     */
    private function findShiftTarget(array $slot, int $currentHour, array $effectiveLoadW, array $supplyCapW): ?int
    {
        $earliest = max(0,  $slot['earliest_start'] ?? 0);
        $latest   = min(24, $slot['latest_end']      ?? 24);

        $bestHour   = null;
        $bestSurplus = -PHP_FLOAT_MAX;

        for ($h = $earliest; $h < $latest; $h++) {
            if ($h === $currentHour) continue;
            if ($slot['active_hours'][$h]) continue; // already scheduled here

            $headroom = $supplyCapW[$h] - $effectiveLoadW[$h] - $slot['peak_w'];
            if ($headroom >= 0 && ($supplyCapW[$h] - $effectiveLoadW[$h]) > $bestSurplus) {
                $bestSurplus = $supplyCapW[$h] - $effectiveLoadW[$h];
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
