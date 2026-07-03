<?php

namespace App\Services;

use App\Models\Project;

/**
 * Computes a 24-element marginal cost signal (currency/kWh per hour) for a project.
 *
 * Answers: "If I had 1 extra kW of load this hour, what would it cost?"
 *
 * Uses the already-computed SourceDispatchService result — the full simulation
 * is NOT re-run. The key input is `battery_remaining_capacity_kw[h]`, which
 * SourceDispatchService now provides: the unused battery discharge headroom
 * (ceiling − actual discharge) for each hour.
 *
 * Cost ladder, cheapest → most expensive:
 *   0.00  — Solar surplus  (solar generated > load — free energy spilling)
 *   0.01  — Battery stored (remaining discharge capacity > 0 — stored solar)
 *   tariff — Grid base rate
 *   peak_tariff — Grid peak-period rate (peak_hours_start ≤ h < peak_hours_end)
 *   (fuel_cost × lph) / gen_kw — Generator marginal fuel cost
 *   999.0 — No source / load shedding
 */
class CostSignalService
{
    private const BATTERY_DEGRADATION_COST = 0.01;  // near-zero — only cell wear
    private const UNMET_COST               = 999.0; // load-shedding sentinel

    /**
     * @param  Project $project        Project model (utility/generator lines loaded lazily).
     * @param  int     $month          Calendar month 1–12 (unused in cost logic itself,
     *                                 kept for interface consistency / future seasonal tariffs).
     * @param  array   $dispatchResult Output of SourceDispatchService::dispatch().
     *                                 Must contain battery_remaining_capacity_kw[24].
     * @param  array   $loadW          Hourly load profile in Watts [0..23].
     * @param  array   $solarW         Hourly solar generation in Watts [0..23].
     */
    public function compute(
        Project $project,
        int     $month,
        array   $dispatchResult,
        array   $loadW,
        array   $solarW,
    ): array {
        // ── Tariff data from the first configured utility line ────────────────
        $utilLine = $project->utilityLines()
            ->whereNotNull('tariff_per_kwh')
            ->orderBy('id')
            ->first();

        $tariff        = $utilLine ? (float) $utilLine->tariff_per_kwh        : null;
        $peakTariff    = ($utilLine && $utilLine->peak_tariff_per_kwh !== null)
                         ? (float) $utilLine->peak_tariff_per_kwh              : null;
        $peakStart     = $utilLine ? (int) $utilLine->peak_hours_start         : null;
        $peakEnd       = $utilLine ? (int) $utilLine->peak_hours_end           : null;
        $utilAvailable = $project->utilityLines()->exists();

        // ── Generator marginal cost from the first configured generator line ──
        $genLine = $project->generatorLines()
            ->whereNotNull('fuel_cost_per_liter')
            ->whereNotNull('fuel_consumption_lph')
            ->where('fuel_cost_per_liter', '>', 0)
            ->where('fuel_consumption_lph', '>', 0)
            ->orderBy('id')
            ->first();

        // Marginal cost: d(fuel_cost)/d(kW) = fuel_price × (F_rated − F₀) / P_rated
        // This is the TRUE incremental cost of asking the generator for 1 more kW,
        // which is what the cost signal represents. It is lower than the average
        // cost/kWh because the no-load fuel overhead is already sunk.
        $genCostPerKwh = null;
        if ($genLine) {
            $mc = $genLine->marginalCostPerKwh();
            if ($mc > 0) {
                $genCostPerKwh = $mc;
            }
        }
        $genAvailable = $project->generatorLines()->exists();

        // ── Battery remaining discharge capacity (kW per hour) ────────────────
        // Provided by SourceDispatchService: ceiling − actual discharge.
        // > 0 means the battery could still serve more load in that hour.
        $battRemKw = $dispatchResult['battery_remaining_capacity_kw'] ?? array_fill(0, 24, 0.0);

        // ── Per-hour cost signal ──────────────────────────────────────────────
        $costSignal   = [];
        $solarSurplus = [];

        for ($h = 0; $h < 24; $h++) {
            $solarKw = max(0.0, (float) ($solarW[$h] ?? 0)) / 1000.0;
            $loadKw  = max(0.0, (float) ($loadW[$h]  ?? 0)) / 1000.0;

            // Surplus solar: generated more than load → next kW is free.
            $surplus          = max(0.0, $solarKw - $loadKw);
            $solarSurplus[$h] = round($surplus, 3);

            if ($surplus > 0.0) {
                $costSignal[$h] = 0.0;
                continue;
            }

            // Battery: remaining discharge headroom > 0 → stored solar available.
            if ((float) ($battRemKw[$h] ?? 0) > 0.0) {
                $costSignal[$h] = self::BATTERY_DEGRADATION_COST;
                continue;
            }

            // Grid utility
            if ($utilAvailable && $tariff !== null) {
                $inPeak = $peakTariff !== null
                       && $peakStart  !== null
                       && $peakEnd    !== null
                       && $h >= $peakStart
                       && $h <  $peakEnd;

                $costSignal[$h] = $inPeak ? $peakTariff : $tariff;
                continue;
            }

            // Generator
            if ($genAvailable && $genCostPerKwh !== null) {
                $costSignal[$h] = $genCostPerKwh;
                continue;
            }

            // No source
            $costSignal[$h] = self::UNMET_COST;
        }

        // ── Classify hours ────────────────────────────────────────────────────
        // baseline = grid base tariff (or generator cost if no grid, or 999 if nothing).
        // baseline = grid base tariff (or generator cost if no grid, else 999).
        // cheap   = cost > 0 AND cost < baseline  (battery degradation hours)
        // normal  = cost == baseline               (standard tariff — not listed)
        // expensive = cost > baseline              (peak tariff / generator / unmet)
        $baseline       = $tariff ?? $genCostPerKwh ?? self::UNMET_COST;
        $freeHours      = [];
        $cheapHours     = [];
        $expensiveHours = [];

        foreach ($costSignal as $h => $cost) {
            if ($cost === 0.0) {
                $freeHours[] = $h;
            } elseif ($cost < $baseline) {
                $cheapHours[] = $h;
            } elseif ($cost > $baseline) {
                $expensiveHours[] = $h;
            }
            // cost == baseline → standard tariff hour, intentionally unlisted
        }

        return [
            'cost_signal'      => array_map(fn($c) => round((float) $c, 4), $costSignal),
            'solar_surplus_kw' => $solarSurplus,
            'free_hours'       => array_values($freeHours),
            'cheap_hours'      => array_values($cheapHours),
            'expensive_hours'  => array_values($expensiveHours),
            'currency_symbol'  => $project->currency_symbol ?? '$',
            'meta' => [
                'utility_tariff_per_kwh'   => $tariff,
                'peak_tariff_per_kwh'      => $peakTariff,
                'peak_hours_start'         => $peakStart,
                'peak_hours_end'           => $peakEnd,
                'generator_cost_per_kwh'   => $genCostPerKwh,
                'battery_degradation_cost' => self::BATTERY_DEGRADATION_COST,
                'has_battery_storage'      => (bool) ($dispatchResult['has_battery_storage'] ?? false),
                'baseline_cost_per_kwh'    => $baseline,
            ],
        ];
    }
}
