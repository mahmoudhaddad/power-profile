<?php

namespace App\Services;

use App\Models\Project;

/**
 * Financial Analysis Service
 *
 * Computes a full economic profile for a project by:
 *   1. Running the real dispatch simulation for the given month
 *   2. Running a baseline dispatch (no solar, no batteries) for comparison
 *   3. Deriving annual energy, costs, savings, investment, payback, and a
 *      25-year cumulative cash-flow projection
 *
 * Panel degradation: 0.5 %/yr (industry standard crystalline silicon)
 * Projection horizon: 25 years
 * Battery replacement: triggered when rated_cycle_life is exhausted
 * LCOE: Levelized Cost of Energy ($/kWh) over 25-year panel lifetime
 */
class FinancialAnalysisService
{
    private const PANEL_DEGRADATION = 0.005; // 0.5 % per year
    private const PROJECTION_YEARS  = 25;
    private const DF_PROJECT        = 0.7;
    private const DEFAULT_WORK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    public function __construct(
        private SolarIrradianceService $solarSvc,
        private SourceDispatchService  $dispatchSvc,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────

    public function analyzeProject(Project $project, int $month): array
    {
        // ── Build profiles ────────────────────────────────────────────────────
        $components = $this->collectComponents($project);
        $loadW      = $this->buildHourlyW($components, 'monday', $month);

        $solarSystems   = $project->solarSystems()->where('is_active', true)->get();
        $solarMode      = $project->solar_source ?? 'max';

        if ($solarMode === 'existing') {
            $solarCapacityW = $solarSystems->isNotEmpty()
                ? $solarSystems->sum('capacity_kw') * 1000.0
                : (float) ($project->existing_solar_power ?? 0)
                  + (float) $project->buildings()->sum('existing_solar_power');
        } else {
            $totalAreaM2    = (float) $project->buildings()->sum('area');
            $solarCapacityW = SolarIrradianceService::estimateCapacityW($totalAreaM2);
        }

        $lat = $project->location_lat !== null ? (float) $project->location_lat : null;
        $lng = $project->location_lng !== null ? (float) $project->location_lng : null;

        $solarProfile = $this->solarSvc->getHourlyOutputWatts(
            $lat, $lng, $month, $solarCapacityW / 1000.0, performanceRatio: 0.80, day: 15
        );

        $utilCapW = (float) $project->utilityLines()->sum('power')  * 0.8;
        $genCapW  = (float) $project->generatorLines()->sum('power') * 0.8;

        $batteries = $project->batteries()->where('is_active', true)->get();
        $battPass  = $batteries->isNotEmpty() ? $batteries : null;

        // ── STEP 1: Normal dispatch → annualize ──────────────────────────────
        $dispatch = $this->dispatchSvc->dispatch(
            $loadW, $solarProfile, $utilCapW, $genCapW,
            $battPass, $solarCapacityW,
            $solarSystems->isNotEmpty() ? $solarSystems : null
        );
        $stats = $dispatch['stats'];

        $solarKwhAnnual   = round(($stats['solar_kwh']                   ?? 0) * 365, 2);
        $gridKwhAnnual    = round(($stats['utility_kwh']                  ?? 0) * 365, 2);
        $genKwhAnnual     = round(($stats['generator_kwh']                ?? 0) * 365, 2);
        $battLossAnnual   = round(($stats['battery_efficiency_loss_kwh']  ?? 0) * 365, 2);
        $totalLoadAnnual  = round(($stats['total_load_kwh']               ?? 0) * 365, 2);

        // ── STEP 2: Weighted average grid tariff ─────────────────────────────
        $utilLine       = $project->utilityLines()->whereNotNull('tariff_per_kwh')->orderBy('id')->first();
        $tariff         = $utilLine ? (float) $utilLine->tariff_per_kwh : 0.0;
        $peakTariff     = ($utilLine && $utilLine->peak_tariff_per_kwh !== null)
                          ? (float) $utilLine->peak_tariff_per_kwh : null;
        $peakStart      = $utilLine ? (int) ($utilLine->peak_hours_start ?? 0) : 0;
        $peakEnd        = $utilLine ? (int) ($utilLine->peak_hours_end   ?? 0) : 0;

        if ($peakTariff !== null && $peakEnd > $peakStart) {
            $peakHours    = $peakEnd - $peakStart;
            $offPeakHours = 24 - $peakHours;
            $weightedTariff = ($offPeakHours * $tariff + $peakHours * $peakTariff) / 24.0;
        } else {
            $weightedTariff = $tariff;
        }

        // ── STEP 3: Generator fuel line ───────────────────────────────────────
        $genLine = $project->generatorLines()
            ->whereNotNull('fuel_cost_per_liter')
            ->whereNotNull('fuel_consumption_lph')
            ->where('fuel_cost_per_liter', '>', 0)
            ->where('fuel_consumption_lph', '>', 0)
            ->orderBy('id')->first();

        // Effective cost/kWh at rated load — used for display and simple payback
        $genCostPerKwh = $genLine ? ($genLine->getCostPerKwhAttribute() ?? 0.0) : 0.0;

        // ── STEP 3 continued: Annual costs using hourly affine fuel model ─────
        // Instead of kWh × flat_rate, we sum actual fuel cost hour-by-hour using
        // F(P) = F₀ + (F_rated − F₀) × P/P_rated so part-load inefficiency is
        // captured accurately (running at 50 % load costs ~30 % more per kWh).
        $genCostAnnual = 0.0;
        if ($genLine) {
            $fuelCostDay = 0.0;
            foreach ($dispatch['generator_used'] as $watt) {
                $kW = (float) $watt / 1000.0;
                if ($kW > 0.001) {
                    $fuelCostDay += $genLine->fuelAtLoadKw($kW)
                                  * (float) $genLine->fuel_cost_per_liter;
                }
            }
            $genCostAnnual = round($fuelCostDay * 365, 2);
        }

        $gridCostAnnual  = round($gridKwhAnnual * $weightedTariff, 2);
        $maintenanceCost = round((float) $solarSystems->sum('annual_maintenance_cost'), 2);
        $totalWithSolar  = round($gridCostAnnual + $genCostAnnual + $maintenanceCost, 2);

        // ── STEP 4: Baseline dispatch (no solar, no battery) ─────────────────
        $baselineDispatch = $this->dispatchSvc->dispatch(
            $loadW, array_fill(0, 24, 0.0), $utilCapW, $genCapW,
            null, 0.0, null
        );
        $bStats = $baselineDispatch['stats'];

        $baselineGridKwh  = round(($bStats['utility_kwh']   ?? 0) * 365, 2);
        $baselineGridCost = round($baselineGridKwh * $weightedTariff, 2);

        // Baseline generator cost also uses the affine model
        $baselineGenCost = 0.0;
        if ($genLine) {
            $bFuelCostDay = 0.0;
            foreach ($baselineDispatch['generator_used'] as $watt) {
                $kW = (float) $watt / 1000.0;
                if ($kW > 0.001) {
                    $bFuelCostDay += $genLine->fuelAtLoadKw($kW)
                                   * (float) $genLine->fuel_cost_per_liter;
                }
            }
            $baselineGenCost = round($bFuelCostDay * 365, 2);
        }

        $totalWithout = round($baselineGridCost + $baselineGenCost, 2);

        // ── STEP 5: Savings ───────────────────────────────────────────────────
        // annual_savings already accounts for maintenance (per spec formula)
        $annualSavings = round($totalWithout - ($gridCostAnnual + $genCostAnnual + $maintenanceCost), 2);
        $savingsPct    = $totalWithout > 0
            ? round(($annualSavings / $totalWithout) * 100, 1)
            : 0.0;

        // ── STEP 6: Investment & payback ──────────────────────────────────────
        $solarInstallCost = round((float) $solarSystems->sum('installation_cost'), 2);
        $batteryPurchCost = round((float) $batteries->sum(fn($b) => $b->purchase_cost ?? 0), 2);
        $totalInvestment  = round($solarInstallCost + $batteryPurchCost, 2);

        $simplePayback = ($annualSavings > 0 && $totalInvestment > 0)
            ? round($totalInvestment / $annualSavings, 1)
            : null;

        // LCOE: Levelized Cost of Energy over 25 years ($/kWh)
        $lcoeSolar = 0.0;
        $lcoeNumerator = $solarInstallCost + $maintenanceCost * self::PROJECTION_YEARS;
        $lcoeDenominator = $solarKwhAnnual * self::PROJECTION_YEARS;
        if ($lcoeDenominator > 0 && $lcoeNumerator > 0) {
            $lcoeSolar = round($lcoeNumerator / $lcoeDenominator, 4);
        }

        // ── STEP 7: 25-year projection ────────────────────────────────────────
        // Map: year → battery replacement cost due that year
        $battReplByYear = [];
        foreach ($batteries as $b) {
            if (! ($b->replacement_cost ?? 0)) continue;
            // years from now until cycle life is exhausted (1 cycle/day approximation)
            $yearsToEol = max(0.0, ($b->rated_cycle_life / 365.0) - (float) $b->age_years);
            $replYear   = (int) ceil($yearsToEol);
            if ($replYear >= 1 && $replYear <= self::PROJECTION_YEARS) {
                $battReplByYear[$replYear] = ($battReplByYear[$replYear] ?? 0.0)
                                          + (float) $b->replacement_cost;
            }
        }

        $cumulative  = [];
        // Start at -investment so cumulative tracks investment break-even, not just
        // operational cash flow. payback_year is the year the line crosses zero.
        $runningNet  = -$totalInvestment;
        $paybackYear = null;

        for ($y = 1; $y <= self::PROJECTION_YEARS; $y++) {
            $degradFactor = (1 - self::PANEL_DEGRADATION) ** $y;
            // annual_savings already includes maintenance deduction (Step 5),
            // so only battery replacement cost is additional here.
            $yearNet      = ($annualSavings * $degradFactor) - ($battReplByYear[$y] ?? 0.0);
            $runningNet  += $yearNet;
            $cumulative[] = round($runningNet, 2);

            if ($paybackYear === null && $runningNet > 0) {
                $paybackYear = $y;
            }
        }

        // ── Energy mix percentages ────────────────────────────────────────────
        $solarPct = $totalLoadAnnual > 0 ? round($solarKwhAnnual / $totalLoadAnnual * 100, 1) : 0.0;
        $gridPct  = $totalLoadAnnual > 0 ? round($gridKwhAnnual  / $totalLoadAnnual * 100, 1) : 0.0;
        $genPct   = $totalLoadAnnual > 0 ? round($genKwhAnnual   / $totalLoadAnnual * 100, 1) : 0.0;

        return [
            'annual_energy' => [
                'solar_kwh'         => $solarKwhAnnual,
                'grid_kwh'          => $gridKwhAnnual,
                'generator_kwh'     => $genKwhAnnual,
                'battery_loss_kwh'  => $battLossAnnual,
                'total_load_kwh'    => $totalLoadAnnual,
                'solar_percent'     => $solarPct,
                'grid_percent'      => $gridPct,
                'generator_percent' => $genPct,
            ],
            'annual_costs' => [
                'grid_cost'          => $gridCostAnnual,
                'generator_cost'     => $genCostAnnual,
                'maintenance_cost'   => $maintenanceCost,
                'total_with_solar'   => $totalWithSolar,
                'total_without_solar'=> $totalWithout,
                'weighted_tariff'    => round($weightedTariff, 4),
                'generator_cost_per_kwh' => round($genCostPerKwh, 4),
            ],
            'savings' => [
                'annual_savings'  => $annualSavings,
                'savings_percent' => $savingsPct,
            ],
            'investment' => [
                'solar_installation' => $solarInstallCost,
                'battery_purchase'   => $batteryPurchCost,
                'total_investment'   => $totalInvestment,
            ],
            'payback' => [
                'simple_payback_years' => $simplePayback,
                'lcoe_solar_per_kwh'   => $lcoeSolar,
            ],
            'projection_25yr' => [
                'cumulative_net_by_year' => $cumulative,
                'payback_year'           => $paybackYear,
                'total_25yr_benefit'     => round($runningNet, 2),
            ],
            'currency_symbol' => $project->currency_symbol ?? '$',
        ];
    }

    // ── Load-profile builder helpers (mirrors CostSignalController / ScheduleController) ──

    private function collectComponents(Project $project): array
    {
        $pDays    = $project->work_days;
        $pSeasons = $project->working_season_intervals;
        $result   = [];

        $this->extractComponents($result, $project->components, 'project_id', $pDays, $pSeasons, 1.0);

        $buildings = $project->buildings()->with([
            'components',
            'floors.components',
            'floors.rooms.components',
        ])->get();

        foreach ($buildings as $building) {
            $bDays    = $building->work_days ?? $pDays;
            $bSeasons = $building->working_season_intervals ?? $pSeasons;
            $bDfs     = DiversityFactorService::buildingDfs($building->type ?? null);

            $this->extractComponents($result, $building->components, 'building_id', $bDays, $bSeasons, self::DF_PROJECT);

            foreach ($building->getRelation('floors') as $floor) {
                $fDays    = $floor->work_days ?? $bDays;
                $fSeasons = $floor->working_season_intervals ?? $bSeasons;

                $this->extractComponents($result, $floor->components, 'floor_id', $fDays, $fSeasons,
                    $bDfs['floor_to_building'] * self::DF_PROJECT);

                foreach ($floor->rooms as $room) {
                    $rDays    = $room->work_days ?? $fDays;
                    $rSeasons = $room->working_season_intervals ?? $fSeasons;
                    $roomDf   = DiversityFactorService::roomDf($room->type ?? null);

                    $this->extractComponents($result, $room->components, 'room_id', $rDays, $rSeasons,
                        $roomDf * $bDfs['room_to_floor'] * $bDfs['floor_to_building'] * self::DF_PROJECT);
                }
            }
        }

        return $result;
    }

    private function extractComponents(array &$out, $components, string $key, ?array $workDays, ?array $seasons, float $df): void
    {
        foreach ($components as $c) {
            $pf  = max(0.01, (float) ($c->power_factor ?? 1));
            $va  = (float) $c->power * (int) $c->quantity;
            $ivs = $c->usage_time_intervals;
            if (is_string($ivs)) $ivs = json_decode($ivs, true) ?? [];
            $out[] = [
                'va'        => $va,
                'peak_w'    => $va * $pf,
                'df'        => $df,
                'pf'        => $pf,
                'intervals' => (is_array($ivs) && count($ivs)) ? $ivs : [['start' => '08:00', 'end' => '18:00']],
                'season'    => $c->usage_season   ?? 'all',
                'day_type'  => $c->usage_day_type ?? 'all',
                'priority'  => $c->priority,
                'group_key' => $c->group_name ? ($key . '|' . $c->{$key} . '|' . $c->group_name) : null,
                'work_days' => is_string($workDays) ? json_decode($workDays, true) : ($workDays ?? []),
                'seasons'   => is_string($seasons)  ? json_decode($seasons, true)  : ($seasons  ?? []),
            ];
        }
    }

    private function buildHourlyW(array $components, string $dayName, int $month): array
    {
        $groups    = [];
        $ungrouped = [];
        foreach ($components as $c) {
            if ($c['group_key'] === null) {
                $ungrouped[] = $c;
            } else {
                if (! isset($groups[$c['group_key']]) || $c['va'] > $groups[$c['group_key']]['va']) {
                    $groups[$c['group_key']] = $c;
                }
            }
        }
        $active  = array_merge($ungrouped, array_values($groups));
        $profile = array_fill(0, 24, 0.0);

        foreach ($active as $c) {
            $isCritical  = ($c['priority'] === 'critical');
            $effectiveDf = $isCritical ? 1.0 : (float) $c['df'];
            $peakW       = $c['peak_w'] * $effectiveDf;

            if ($isCritical) {
                for ($h = 0; $h < 24; $h++) { $profile[$h] += $peakW; }
                continue;
            }
            if (! $this->activeInMonth($c['seasons'], $month))                continue;
            if (! $this->seasonOk($c['season'], $month))                      continue;
            if (! $this->dayTypeOk($c['work_days'], $c['day_type'], $dayName)) continue;

            foreach ($c['intervals'] as $iv) {
                $start = $this->dec($iv['start'] ?? '00:00');
                $end   = $this->dec($iv['end']   ?? '23:59');
                if ($end <= $start) $end += 24;
                for ($h = 0; $h < 24; $h++) {
                    $mid = $h + 0.5;
                    if (($mid >= $start && $mid < $end) ||
                        ($end > 24 && ($mid + 24) >= $start && ($mid + 24) < $end)) {
                        $profile[$h] += $peakW;
                    }
                }
            }
        }
        return array_map(fn($v) => round($v, 2), $profile);
    }

    private function activeInMonth(?array $intervals, int $month): bool
    {
        if (empty($intervals)) return true;
        $cur = $month * 100 + 15;
        foreach ($intervals as $iv) {
            [$fm, $fd] = array_map('intval', explode('-', $iv['from'] ?? '01-01'));
            [$tm, $td] = array_map('intval', explode('-', $iv['to']   ?? '12-31'));
            $f = $fm * 100 + $fd; $t = $tm * 100 + $td;
            if ($f <= $t ? ($cur >= $f && $cur <= $t) : ($cur >= $f || $cur <= $t)) return true;
        }
        return false;
    }

    private function seasonOk(string $s, int $month): bool
    {
        if ($s === 'all') return true;
        $season = match (true) {
            in_array($month, [3,4,5])   => 'spring',
            in_array($month, [6,7,8])   => 'summer',
            in_array($month, [9,10,11]) => 'autumn',
            default                     => 'winter',
        };
        return $s === $season;
    }

    private function dayTypeOk(?array $workDays, string $compType, string $day): bool
    {
        $wd = $workDays ?? self::DEFAULT_WORK_DAYS;
        $isWork = in_array($day, $wd, true);
        return $compType === 'weekend' ? !$isWork : $isWork;
    }

    private function dec(string $t): float
    {
        [$h, $m] = array_map('intval', explode(':', $t));
        return $h + $m / 60.0;
    }
}
