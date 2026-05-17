<?php

namespace App\Services;

/**
 * Applies priority-ordered source dispatch for each hour of the day.
 *
 * Dispatch priority (highest first):
 *   1. Solar         — free fuel, always preferred
 *   2. Utility grid  — on-demand, lower operating cost than diesel
 *   3. Generator     — highest cost, used only when solar + utility insufficient
 *
 * All power values are in watts (W).
 */
class SourceDispatchService
{
    /**
     * @param float[] $loadW          24-element array — hourly load demand in W
     * @param float[] $solarW         24-element array — hourly solar generation in W
     * @param float   $utilityCapW    Maximum utility capacity in W
     * @param float   $generatorCapW  Maximum generator capacity in W
     *
     * @return array{
     *   solar_used: float[],
     *   utility_used: float[],
     *   generator_used: float[],
     *   unmet: float[],
     *   stats: array
     * }
     */
    public function dispatch(
        array $loadW,
        array $solarW,
        float $utilityCapW,
        float $generatorCapW
    ): array {
        $solarUsed     = [];
        $utilityUsed   = [];
        $generatorUsed = [];
        $unmet         = [];

        for ($h = 0; $h < 24; $h++) {
            $demand    = max(0.0, (float) ($loadW[$h]  ?? 0));
            $solarAvail = max(0.0, (float) ($solarW[$h] ?? 0));
            $remaining = $demand;

            // 1. Solar — use as much as available up to demand
            $sU = min($solarAvail, $remaining);
            $remaining -= $sU;

            // 2. Utility grid
            $uU = min($utilityCapW, $remaining);
            $remaining -= $uU;

            // 3. Generator
            $gU = min($generatorCapW, $remaining);
            $remaining -= $gU;

            $solarUsed[$h]     = round($sU,        2);
            $utilityUsed[$h]   = round($uU,        2);
            $generatorUsed[$h] = round($gU,        2);
            $unmet[$h]         = round(max(0.0, $remaining), 2);
        }

        return [
            'solar_used'     => $solarUsed,
            'utility_used'   => $utilityUsed,
            'generator_used' => $generatorUsed,
            'unmet'          => $unmet,
            'stats'          => $this->stats($solarUsed, $utilityUsed, $generatorUsed, $unmet, $loadW, $solarW),
        ];
    }

    private function stats(
        array $su, array $uu, array $gu, array $unmet, array $load, array $solar
    ): array {
        $sH = $sKwh = 0;
        $uH = $uKwh = 0;
        $gH = $gKwh = 0;
        $unmetKwh = $loadKwh = $solarGenKwh = 0;

        for ($h = 0; $h < 24; $h++) {
            $s = $su[$h]    ?? 0;
            $u = $uu[$h]    ?? 0;
            $g = $gu[$h]    ?? 0;
            $un = $unmet[$h] ?? 0;

            if ($s  > 0) { $sH++; $sKwh += $s / 1000; }
            if ($u  > 0) { $uH++; $uKwh += $u / 1000; }
            if ($g  > 0) { $gH++; $gKwh += $g / 1000; }
            $unmetKwh    += $un / 1000;
            $loadKwh     += ($load[$h]  ?? 0) / 1000;
            $solarGenKwh += ($solar[$h] ?? 0) / 1000;
        }

        // Solar self-consumption ratio
        $solarSelfConsumption = $solarGenKwh > 0 ? round($sKwh / $solarGenKwh * 100, 1) : 0;

        return [
            'solar_hours'            => $sH,
            'solar_kwh'              => round($sKwh,     2),
            'utility_hours'          => $uH,
            'utility_kwh'            => round($uKwh,     2),
            'generator_hours'        => $gH,
            'generator_kwh'          => round($gKwh,     2),
            'unmet_kwh'              => round($unmetKwh, 2),
            'total_load_kwh'         => round($loadKwh,  2),
            'solar_generated_kwh'    => round($solarGenKwh, 2),
            'solar_self_consumption' => $solarSelfConsumption, // % of generated solar that is used
        ];
    }
}
