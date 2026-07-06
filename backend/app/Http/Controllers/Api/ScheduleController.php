<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\DiversityFactorService;
use App\Services\LoadSheddingService;
use App\Services\SolarIrradianceService;
use App\Services\SourceDispatchService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    private const DEFAULT_WORK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    // IEC 60364-8-1 project-level diversity factor (building → project).
    // Floor/room-level DFs are now per-building-type via DiversityFactorService.
    private const DF_PROJECT = 0.7;

    private const MONTH_NAMES = [
        1 => 'January',  2 => 'February',  3 => 'March',    4 => 'April',
        5 => 'May',      6 => 'June',       7 => 'July',     8 => 'August',
        9 => 'September',10 => 'October',  11 => 'November',12 => 'December',
    ];

    public function __construct(
        private SolarIrradianceService $solarSvc,
        private SourceDispatchService  $dispatchSvc,
        private LoadSheddingService    $sheddingSvc,
    ) {}

    private const ALL_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * GET /api/projects/{project}/schedule
     * Query params:
     *   month = 1-12  (default: current month)
     *   day   = 1-31  (default: 15 — selects the solar irradiance day)
     *
     * Returns per-day load profiles for every day of the week so the frontend
     * can show the exact schedule for any picked calendar date without a round-trip.
     */
    public function project(Request $request, Project $project)
    {
        $this->authorize('view', $project);
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $month  = max(1, min(12, (int) $request->query('month', now()->month)));
        $dayNum = max(1, min(31, (int) $request->query('day', 15)));
        $dayNum = min($dayNum, (int) date('t', mktime(0, 0, 0, $month, 1, 2023)));

        // ── 1. Collect all components with schedule metadata ──────────────────
        $components = $this->collectComponents($project);

        // ── 2. Solar capacity ─────────────────────────────────────────────────
        // Max Available: always area-based (roof estimate) — unchanged.
        // Existing System: sum of named solar systems when defined, otherwise
        //   the legacy single existing_solar_power value.
        $solarSystems = $project->solarSystems()->where('is_active', true)->get();
        $solarMode    = $project->solar_source ?? 'max';

        if ($solarMode === 'existing') {
            if ($solarSystems->isNotEmpty()) {
                $solarCapacityW = $solarSystems->sum('capacity_kw') * 1000.0;
            } else {
                $solarCapacityW = (float) ($project->existing_solar_power ?? 0)
                                + (float) $project->buildings()->sum('existing_solar_power');
            }
        } else {
            // 'max' — roof area estimate, exactly as before
            $totalAreaM2    = (float) $project->buildings()->sum('area');
            $solarCapacityW = SolarIrradianceService::estimateCapacityW($totalAreaM2);
        }

        $lat = $project->location_lat !== null ? (float) $project->location_lat : null;
        $lng = $project->location_lng !== null ? (float) $project->location_lng : null;

        $solarProfile = $this->solarSvc->getHourlyOutputWatts(
            $lat, $lng, $month, $solarCapacityW / 1000.0,
            performanceRatio: 0.80, day: $dayNum
        );

        $sunInfo = $lat !== null ? $this->solarSvc->sunriseSunset($lat, $month)
                                 : ['sunrise' => null, 'sunset' => null];
        $psh     = $lat !== null ? round($this->solarSvc->peakSunHours($lat, $month), 2) : null;

        // ── 3. Source capacities ──────────────────────────────────────────────
        $utilCapVA = (float) $project->utilityLines()->sum('power');
        $genCapVA  = (float) $project->generatorLines()->sum('power');
        $utilCapW  = $utilCapVA * 0.8;
        $genCapW   = $genCapVA  * 0.8;

        // Guard: return a structured error if the project has no power sources at all
        $hasSolarSystems = $project->solarSystems()->where('is_active', true)->exists();
        $hasBatteries    = $project->batteries()->where('is_active', true)->exists();
        if ($utilCapW <= 0 && $genCapW <= 0 && $solarCapacityW <= 0 && !$hasSolarSystems && !$hasBatteries) {
            return response()->json([
                'error'   => 'no_sources',
                'message' => 'No power sources are configured for this project. '
                           . 'Please add a utility line, generator, or solar system first.',
            ], 422);
        }

        // ── Cost rates (for daily cost display in the UI) ─────────────────────
        $utilLine = $project->utilityLines()->whereNotNull('tariff_per_kwh')->orderBy('id')->first();
        $tariff      = $utilLine ? (float) $utilLine->tariff_per_kwh : null;
        $peakTariff  = ($utilLine && $utilLine->peak_tariff_per_kwh) ? (float) $utilLine->peak_tariff_per_kwh : null;
        $peakStart   = $utilLine ? (int) ($utilLine->peak_hours_start ?? 0) : null;
        $peakEnd     = $utilLine ? (int) ($utilLine->peak_hours_end   ?? 0) : null;

        $genLine = $project->generatorLines()
            ->whereNotNull('fuel_cost_per_liter')->whereNotNull('fuel_consumption_lph')
            ->where('fuel_cost_per_liter', '>', 0)->where('fuel_consumption_lph', '>', 0)
            ->orderBy('id')->first();
        $genCostPerKwh = null;
        if ($genLine && (float) $genLine->power > 0) {
            $genCostPerKwh = round(
                (float) $genLine->fuel_cost_per_liter * (float) $genLine->fuel_consumption_lph
                / ((float) $genLine->power / 1000.0), 4
            );
        }

        // Include raw generator parameters so the frontend can compute affine
        // hourly fuel cost (F(P) = F₀ + (F_rated-F₀)×P/P_rated) instead of
        // a flat kWh rate, which would be inaccurate at part-load conditions.
        $costRates = [
            'tariff_per_kwh'           => $tariff,
            'peak_tariff_per_kwh'      => $peakTariff,
            'peak_hours_start'         => $peakStart,
            'peak_hours_end'           => $peakEnd,
            'generator_cost_per_kwh'   => $genCostPerKwh,          // rated (100 % load) — kept for fallback
            'generator_rated_kw'       => $genLine ? round((float) $genLine->power / 1000.0, 3) : null,
            'generator_rated_lph'      => $genLine ? (float) $genLine->fuel_consumption_lph : null,
            'generator_no_load_lph'    => $genLine ? ($genLine->no_load_fuel_lph
                                              ?? round((float) $genLine->fuel_consumption_lph * 0.30, 4))
                                              : null,
            'fuel_cost_per_liter'      => $genLine ? (float) $genLine->fuel_cost_per_liter : null,
            'currency_symbol'          => $project->currency_symbol ?? '$',
        ];

        // ── 4. Active batteries (fetched once, used in every day simulation) ───
        $batteries = $project->batteries()->where('is_active', true)->get();
        $battPass  = $batteries->isNotEmpty() ? $batteries : null;

        // ── 4b. Supply capacity estimate for the load-shedding pre-pass ────────
        // supplyCapW (full cap incl. generator + battery) → deficit detection & restoration.
        // shiftCapW  (solar + utility only, no battery, no generator) → shift-target selection.
        //   A shiftable load must only move to an hour where renewable/grid supply alone
        //   can absorb it.  Including battery or generator here would make dark hours look
        //   attractive, drain the battery overnight, and force the generator to compensate.
        $battMaxDischargeW = $batteries->sum(fn($b) => $b->max_discharge_power_kw) * 1000.0;
        $supplyCapW        = array_map(
            fn($solar) => $solar + $utilCapW + $genCapW + $battMaxDischargeW,
            $solarProfile
        );
        $shiftCapW         = array_map(
            fn($solar) => $solar + $utilCapW,   // solar + grid only — no battery, no generator
            $solarProfile
        );

        $solarSystemsArg = $solarSystems->isNotEmpty() ? $solarSystems : null;

        // ── 5. Per-day profiles: one entry per day of the week ────────────────
        // Each day simulation starts from batteries' stored current_soc.
        $days = [];
        foreach (self::ALL_DAYS as $dayName) {
            // Build component slots for shedding pre-pass (mode-matched to dispatch)
            $slotsMax = $this->buildComponentSlots($components, 'max',       $dayName, $month);
            $slotsOpt = $this->buildComponentSlots($components, 'optimized', $dayName, $month);

            $shedMax = $this->sheddingSvc->shed($slotsMax, $supplyCapW, $shiftCapW);
            $shedOpt = $this->sheddingSvc->shed($slotsOpt, $supplyCapW, $shiftCapW);

            // Use adjusted profiles for dispatch; original profiles exposed for UI diff
            $loadMax = $this->buildHourlyW($components, 'max',       $dayName, $month, false);
            $loadOpt = $this->buildHourlyW($components, 'optimized', $dayName, $month, true);

            $days[$dayName] = [
                'load_max'           => $loadMax,
                'load_optimized'     => $loadOpt,
                // Adjusted profiles (post-shed/shift) exposed so the chart demand line
                // matches what the dispatch engine actually received.
                'load_shed_max'      => $shedMax['adjusted_load_w'],
                'load_shed_optimized'=> $shedOpt['adjusted_load_w'],
                'hourly_kvar'        => $this->buildHourlyKvar($components, $dayName, $month),
                'dispatch_max'       => $this->dispatchSvc->dispatch($shedMax['adjusted_load_w'], $solarProfile, $utilCapW, $genCapW, $battPass, $solarCapacityW, $solarSystemsArg),
                'dispatch_optimized' => $this->dispatchSvc->dispatch($shedOpt['adjusted_load_w'], $solarProfile, $utilCapW, $genCapW, $battPass, $solarCapacityW, $solarSystemsArg),
                'shedding_max'       => $this->shedSummary($shedMax),
                'shedding_optimized' => $this->shedSummary($shedOpt),
            ];
        }

        // ── 6. Battery summary ────────────────────────────────────────────────
        if ($batteries->isNotEmpty()) {
            $totalUsableKwh = $batteries->sum(fn($b) => $b->usable_capacity_kwh);
            $avgAge = $batteries->reduce(
                fn($acc, $b) => $acc + $b->age_years * $b->usable_capacity_kwh,
                0.0
            ) / max(0.001, $totalUsableKwh);

            $batterySummary = [
                'bank_count'        => $batteries->count(),
                'total_nominal_kwh' => round($batteries->sum(fn($b) => $b->nominal_capacity_kwh), 2),
                'total_usable_kwh'  => round($totalUsableKwh, 2),
                'average_age_years' => round($avgAge, 2),
                'chemistries'       => $batteries->pluck('chemistry')->unique()->values()->toArray(),
            ];
        } else {
            $batterySummary = null;
        }

        return response()->json([
            'month'                 => $month,
            'month_name'           => self::MONTH_NAMES[$month],
            'day'                   => $dayNum,
            'location'              => ['lat' => $lat, 'lng' => $lng, 'name' => $project->location_name],
            'sunrise_hour'          => $sunInfo['sunrise'] ?? null,
            'sunset_hour'           => $sunInfo['sunset']  ?? null,
            'peak_sun_hours'        => $psh,
            'solar_capacity_w'      => $solarCapacityW,
            'solar_data_source'     => $this->solarSvc->getDataSource(),
            'utility_capacity_va'   => $utilCapVA,
            'generator_capacity_va' => $genCapVA,
            'solar'                 => $solarProfile,
            'solar_systems'         => $solarSystems->values(),
            'battery_summary'       => $batterySummary,
            'cost_rates'            => $costRates,
            'days'                  => $days,
        ]);
    }

    // ── Component collection (mirrors LoadProfileController logic) ────────────

    private function collectComponents(Project $project): array
    {
        $pDays    = $project->work_days;
        $pSeasons = $project->working_season_intervals;
        $result   = [];

        // Project-own components: no diversity reduction at their own level.
        $this->extractRaw($result, $project->components, 'project_id', $pDays, $pSeasons, 1.0);

        // Eager load all project data in one query set to prevent N+1 problems.
        // Without this, a project with 10 buildings × 5 floors × 10 rooms
        // would generate 500+ individual database queries per request.
        $buildings = $project->buildings()->with([
            'components',
            'floors.components',
            'floors.rooms.components',
        ])->get();

        foreach ($buildings as $building) {
            $bDays    = $building->work_days    ?? $pDays;
            $bSeasons = $building->working_season_intervals ?? $pSeasons;
            $bDfs     = DiversityFactorService::buildingDfs($building->type ?? null);

            $this->extractRaw($result, $building->components, 'building_id', $bDays, $bSeasons,
                self::DF_PROJECT);

            foreach ($building->getRelation('floors') as $floor) {
                $fDays    = $floor->work_days    ?? $bDays;
                $fSeasons = $floor->working_season_intervals ?? $bSeasons;

                $this->extractRaw($result, $floor->components, 'floor_id', $fDays, $fSeasons,
                    $bDfs['floor_to_building'] * self::DF_PROJECT);

                foreach ($floor->rooms as $room) {
                    $rDays    = $room->work_days    ?? $fDays;
                    $rSeasons = $room->working_season_intervals ?? $fSeasons;
                    $roomDf   = DiversityFactorService::roomDf($room->type ?? null);

                    $this->extractRaw($result, $room->components, 'room_id', $rDays, $rSeasons,
                        $roomDf * $bDfs['room_to_floor'] * $bDfs['floor_to_building'] * self::DF_PROJECT);
                }
            }
        }

        return $result;
    }

    private function extractRaw(array &$out, $components, string $key, ?array $workDays, ?array $seasons, float $df = 1.0): void
    {
        foreach ($components as $c) {
            $pf = max(0.01, (float) ($c->power_factor ?? 1));
            $va = (float) $c->power * (int) $c->quantity; // S = VA_rated × qty — used for group-max selection
            $out[] = [
                'va'               => $va,
                'peak_w'           => $va * $pf,  // undiversified P = S × PF
                'df'               => $df,
                'pf'               => $pf,
                'intervals'        => $c->usage_time_intervals ?? [['start' => '08:00', 'end' => '18:00']],
                'season'           => $c->usage_season   ?? 'all',
                'day_type'         => $c->usage_day_type ?? 'all',
                'priority'         => $c->priority       ?? 'normal',
                'group_key'        => $c->group_name ? ($key . '|' . $c->{$key} . '|' . $c->group_name) : null,
                'work_days'        => $workDays,
                'seasons'          => $seasons,
                // Demand-side shedding fields
                'load_flexibility' => $c->load_flexibility     ?? 'fixed',
                'curtail_min_pct'  => (int) ($c->curtail_min_pct      ?? 0),
                'earliest_start'   => (int) ($c->earliest_start_hour  ?? 0),
                'latest_end'       => (int) ($c->latest_end_hour      ?? 24),
                'required_run_h'   => (int) ($c->required_run_hours   ?? 0),
                'label'            => "type{$c->component_type_id}/{$c->power}W",
            ];
        }
    }

    /**
     * Build per-component slots with pre-computed active_hours[24] arrays.
     * Used by LoadSheddingService to perform component-level shedding/shifting.
     *
     * Applies the same group-max and season/day-type filtering as buildHourlyW()
     * so the shedding pre-pass sees exactly the same set of loads as dispatch.
     */
    private function buildComponentSlots(array $components, string $mode, string $dayName, int $month): array
    {
        // Apply group-max in optimized mode (mirrors buildHourlyW logic)
        if ($mode === 'optimized') {
            $groups    = [];
            $ungrouped = [];
            foreach ($components as $c) {
                if ($c['group_key'] === null) {
                    $ungrouped[] = $c;
                } else {
                    if (!isset($groups[$c['group_key']]) || $c['va'] > $groups[$c['group_key']]['va']) {
                        $groups[$c['group_key']] = $c;
                    }
                }
            }
            $components = array_merge($ungrouped, array_values($groups));
        }

        $applyDiversity = $mode === 'optimized';
        $slots          = [];

        foreach ($components as $c) {
            $isCritical = ($c['priority'] === 'critical');

            // Apply the same season/day-type filters as buildHourlyW
            if (!$isCritical) {
                if (!$this->activeInMonth($c['seasons'], $month))                  continue;
                if (!$this->componentSeasonOk($c['season'], $month))               continue;
                if (!$this->dayTypeOk($c['work_days'], $c['day_type'], $dayName))  continue;
            }

            $effectiveDf = ($applyDiversity && !$isCritical) ? (float) $c['df'] : 1.0;
            $peakW       = $c['peak_w'] * $effectiveDf;
            if ($peakW <= 0) continue;

            // Compute active_hours using the same interval arithmetic as buildHourlyW
            $active = array_fill(0, 24, false);
            if ($isCritical) {
                $active = array_fill(0, 24, true);
            } else {
                foreach ($c['intervals'] as $iv) {
                    $start = $this->dec($iv['start'] ?? '00:00');
                    $end   = $this->dec($iv['end']   ?? '23:59');
                    if ($end <= $start) $end += 24;
                    for ($h = 0; $h < 24; $h++) {
                        $mid = $h + 0.5;
                        if (($mid >= $start && $mid < $end) ||
                            ($end > 24 && ($mid + 24) >= $start && ($mid + 24) < $end)) {
                            $active[$h] = true;
                        }
                    }
                }
            }

            if (!in_array(true, $active, true)) continue;

            $slots[] = [
                'peak_w'          => $peakW,
                'priority'        => $c['priority'],
                'load_flexibility'=> $c['load_flexibility'],
                'active_hours'    => $active,
                'curtail_min_pct' => (float) $c['curtail_min_pct'],
                'earliest_start'  => $c['earliest_start'],
                'latest_end'      => $c['latest_end'],
                'required_run_h'  => $c['required_run_h'],
                'label'           => $c['label'],
            ];
        }

        return $slots;
    }

    // ── 24-hour profile builder ───────────────────────────────────────────────

    private function buildHourlyW(array $components, string $mode, string $dayName, int $month, bool $applyDiversity = false): array
    {
        if ($mode === 'optimized') {
            // Group-max: keep only the highest-VA component per group.
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
            $components = array_merge($ungrouped, array_values($groups));
        }

        $profile = array_fill(0, 24, 0.0);

        foreach ($components as $c) {
            $isCritical  = ($c['priority'] === 'critical');
            $effectiveDf = ($applyDiversity && ! $isCritical) ? (float) $c['df'] : 1.0;
            $peakW       = $c['peak_w'] * $effectiveDf;

            if ($isCritical) {
                for ($h = 0; $h < 24; $h++) { $profile[$h] += $peakW; }
                continue;
            }

            if (! $this->activeInMonth($c['seasons'], $month))            continue;
            if (! $this->componentSeasonOk($c['season'], $month))         continue;
            if (! $this->dayTypeOk($c['work_days'], $c['day_type'], $dayName)) continue;

            foreach ($c['intervals'] as $iv) {
                $start = $this->dec($iv['start'] ?? '00:00');
                $end   = $this->dec($iv['end']   ?? '23:59');
                if ($end <= $start) $end += 24;

                for ($h = 0; $h < 24; $h++) {
                    $mid = $h + 0.5;
                    if ($mid >= $start && $mid < $end) {
                        $profile[$h] += $peakW;
                    } elseif ($end > 24 && ($mid + 24) >= $start && ($mid + 24) < $end) {
                        $profile[$h] += $peakW;
                    }
                }
            }
        }

        return array_map(fn($v) => round($v, 2), $profile);
    }

    // ── Schedule filter helpers ───────────────────────────────────────────────

    private function activeInMonth(?array $seasonIntervals, int $month): bool
    {
        if (empty($seasonIntervals)) return true;

        $curOrd = $month * 100 + 15; // mid-month ordinal
        foreach ($seasonIntervals as $iv) {
            [$fm, $fd] = array_map('intval', explode('-', $iv['from'] ?? '01-01'));
            [$tm, $td] = array_map('intval', explode('-', $iv['to']   ?? '12-31'));
            $fromOrd = $fm * 100 + $fd;
            $toOrd   = $tm * 100 + $td;

            if ($fromOrd <= $toOrd) {
                if ($curOrd >= $fromOrd && $curOrd <= $toOrd) return true;
            } else {
                if ($curOrd >= $fromOrd || $curOrd <= $toOrd) return true;
            }
        }
        return false;
    }

    private function componentSeasonOk(string $usageSeason, int $month): bool
    {
        if ($usageSeason === 'all') return true;
        $s = match (true) {
            in_array($month, [3, 4, 5])   => 'spring',
            in_array($month, [6, 7, 8])   => 'summer',
            in_array($month, [9, 10, 11]) => 'autumn',
            default                       => 'winter',
        };
        return $usageSeason === $s;
    }

    /**
     * Determine whether a component is active on a specific day of the week.
     *
     * $actualDayName: 'monday' | 'tuesday' | … | 'sunday'
     * $compDayType:   'weekday' | 'weekend' | 'all'   (stored on the component)
     * $workDays:      the entity's configured work days (null → project default Mon-Fri)
     *
     * Logic:
     *  - 'weekday'  → runs only on days that ARE in the entity's work_days
     *  - 'weekend'  → runs only on days that are NOT in the entity's work_days
     *  - 'all'      → follows the entity's work_days (same as 'weekday')
     */
    private function dayTypeOk(?array $workDays, string $compDayType, string $actualDayName): bool
    {
        $effectiveWorkDays = $workDays ?? self::DEFAULT_WORK_DAYS;
        $isWorkday         = in_array($actualDayName, $effectiveWorkDays, true);

        if ($compDayType === 'weekend') return ! $isWorkday;

        // 'weekday', 'workday' (legacy), 'all' → active only on entity's work days
        return $isWorkday;
    }

    private function dec(string $t): float
    {
        [$h, $m] = array_map('intval', explode(':', $t));
        return $h + $m / 60.0;
    }

    private function buildHourlyKvar(array $components, string $dayName, int $month): array
    {
        $hourlyQ = array_fill(0, 24, 0.0);

        foreach ($components as $c) {
            $peakW = (float) $c['peak_w'];
            if ($peakW <= 0) continue;
            $pf = max(0.01, min(1.0, (float) ($c['pf'] ?? 1.0)));
            if ($pf >= 1.0) continue;

            $isCritical  = ($c['priority'] === 'critical');
            $effectiveDf = $isCritical ? 1.0 : (float) $c['df'];
            $qi          = $peakW * $effectiveDf * tan(acos($pf));

            if ($isCritical) {
                for ($h = 0; $h < 24; $h++) { $hourlyQ[$h] += $qi; }
                continue;
            }

            if (! $this->activeInMonth($c['seasons'], $month))                  continue;
            if (! $this->componentSeasonOk($c['season'], $month))               continue;
            if (! $this->dayTypeOk($c['work_days'], $c['day_type'], $dayName))  continue;

            foreach ($c['intervals'] as $iv) {
                $start = $this->dec($iv['start'] ?? '00:00');
                $end   = $this->dec($iv['end']   ?? '23:59');
                if ($end <= $start) $end += 24;

                for ($h = 0; $h < 24; $h++) {
                    $mid = $h + 0.5;
                    if (($mid >= $start && $mid < $end) ||
                        ($end > 24 && ($mid + 24) >= $start && ($mid + 24) < $end)) {
                        $hourlyQ[$h] += $qi;
                    }
                }
            }
        }

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = round($hourlyQ[$h] / 1000, 2);
        }
        return $result;
    }

    /**
     * Flatten a LoadSheddingService result into the response shape.
     * Kept separate so per_load_shed_list is only included when non-empty.
     */
    private function shedSummary(array $result): array
    {
        $summary = [
            'shed_curtailable_kwh' => $result['shed_curtailable_kwh'],
            'shed_normal_kwh'      => $result['shed_normal_kwh'],
            'shed_essential_kwh'   => $result['shed_essential_kwh'],
            'critical_unmet_kwh'   => $result['critical_unmet_kwh'],
            'any_shedding'         => ($result['shed_curtailable_kwh']
                                       + $result['shed_normal_kwh']
                                       + $result['shed_essential_kwh']
                                       + $result['critical_unmet_kwh']) > 0,
        ];

        if (!empty($result['per_load_shed_list'])) {
            $summary['per_load_shed_list'] = $result['per_load_shed_list'];
        }

        return $summary;
    }

    // ── Chemistry Comparison ──────────────────────────────────────────────────

    /**
     * GET /api/projects/{project}/battery-chemistry-comparison
     * Query params: month (1-12), day (1-31), day_type (weekday|weekend)
     *
     * Runs the same load + solar profile twice — once with a lead-acid bank
     * (DoD 50 %, RTE 82 %) and once with LFP (DoD 85 %, RTE 92 %) — both
     * sharing the project's actual total nominal capacity.
     * Returns generator kWh, hours, and affine fuel cost so the UI can show
     * how chemistry choice affects generator usage for the same building.
     */
    public function chemistryComparison(Request $request, Project $project)
    {
        $this->authorize('view', $project);
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $month   = max(1, min(12, (int) $request->query('month', now()->month)));
        $dayNum  = max(1, min(31, (int) $request->query('day', 15)));
        $dayNum  = min($dayNum, (int) date('t', mktime(0, 0, 0, $month, 1, 2023)));
        $dayType = $request->query('day_type', 'weekday');
        $dayName = $dayType === 'weekend' ? 'saturday' : 'monday';

        // Load profile for a representative day
        $components = $this->collectComponents($project);
        $loadW      = $this->buildHourlyW($components, 'max', $dayName, $month, false);

        // Solar
        $solarSystems = $project->solarSystems()->where('is_active', true)->get();
        $solarMode    = $project->solar_source ?? 'max';
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
            $lat, $lng, $month, $solarCapacityW / 1000.0, performanceRatio: 0.80, day: $dayNum
        );

        // Source capacities
        $utilCapW = (float) $project->utilityLines()->sum('power') * 0.8;
        $genCapW  = (float) $project->generatorLines()->sum('power') * 0.8;

        // Reference nominal capacity from actual batteries (default 10 kWh for illustration)
        $batteries  = $project->batteries()->where('is_active', true)->get();
        $nominalKwh = $batteries->isNotEmpty()
            ? $batteries->sum(fn($b) => $b->nominal_capacity_kwh)
            : 10.0;
        $avgSoc     = $batteries->isNotEmpty()
            ? round($batteries->avg('current_soc'), 3)
            : 0.5;

        // Generator fuel params for affine cost (ISO 8528: F(P) = F₀ + (F_rated-F₀)×P/P_rated)
        $genLine = $project->generatorLines()
            ->whereNotNull('fuel_cost_per_liter')->whereNotNull('fuel_consumption_lph')
            ->where('fuel_cost_per_liter', '>', 0)->where('fuel_consumption_lph', '>', 0)
            ->orderBy('id')->first();

        // Chemistry specs: same nominal, different DoD and RTE
        $chemSpecs = [
            'lead_acid' => [
                'label' => 'Lead-Acid',
                'dod'   => 0.50,  // ⚠ tunable — matches BatteryChemistryService flooded DoD
                'rte'   => 0.82,  // ⚠ tunable — matches BatteryChemistryService flooded RTE
                'c_chg' => 0.10,  // C/10 charge rate
                'c_dch' => 0.20,  // C/5  discharge rate
            ],
            'lithium_lfp' => [
                'label' => 'Lithium LFP',
                'dod'   => 0.85,  // ⚠ tunable — matches BatteryChemistryService lithium_lfp DoD
                'rte'   => 0.92,  // ⚠ tunable — matches BatteryChemistryService lithium_lfp RTE
                'c_chg' => 0.50,
                'c_dch' => 1.00,
            ],
        ];

        $comparison = [];
        foreach ($chemSpecs as $key => $spec) {
            $usableKwh = $nominalKwh * $spec['dod'];
            $fakeBatt  = new Collection([(object) [
                'id'                     => 1,
                'is_active'              => true,
                'usable_capacity_kwh'    => $usableKwh,
                'current_soc'            => max(0.0, min(1.0, $avgSoc)),
                'max_charge_power_kw'    => $nominalKwh * $spec['c_chg'],
                'max_discharge_power_kw' => $nominalKwh * $spec['c_dch'],
                'round_trip_efficiency'  => $spec['rte'],
                'solar_system_id'        => null,
            ]]);

            $dispatch = $this->dispatchSvc->dispatch(
                $loadW, $solarProfile, $utilCapW, $genCapW, $fakeBatt, $solarCapacityW,
                $solarSystems->isNotEmpty() ? $solarSystems : null
            );

            $stats  = $dispatch['stats'];
            $genKwh = $stats['generator_kwh'];

            // Affine hourly fuel cost
            $fuelCost = null;
            if ($genLine && $genCapW > 0) {
                $ratedKw = $genCapW / 1000.0;
                $fRated  = (float) $genLine->fuel_consumption_lph;
                $f0      = $genLine->no_load_fuel_lph ?? round($fRated * 0.30, 4);
                $cpL     = (float) $genLine->fuel_cost_per_liter;
                $litres  = 0.0;
                foreach ($dispatch['generator_used'] as $watt) {
                    if ($watt > 0) {
                        $kw     = $watt / 1000.0;
                        $litres += $f0 + ($fRated - $f0) * ($kw / $ratedKw);
                    }
                }
                $fuelCost = round($litres * $cpL, 2);
            }

            $comparison[$key] = [
                'label'                  => $spec['label'],
                'dod_pct'                => $spec['dod'] * 100,
                'rte_pct'                => $spec['rte'] * 100,
                'nominal_kwh'            => round($nominalKwh, 2),
                'usable_kwh'             => round($usableKwh, 2),
                'generator_hours'        => $stats['generator_hours'],
                'generator_kwh'          => $genKwh,
                'battery_discharged_kwh' => $stats['battery_discharged_kwh'] ?? 0.0,
                'unmet_kwh'              => $stats['unmet_kwh'],
                'fuel_cost'              => $fuelCost,
            ];
        }

        // Delta: LFP advantage over lead-acid
        $la  = $comparison['lead_acid'];
        $lfp = $comparison['lithium_lfp'];
        $delta = [
            'generator_kwh_saved'   => round($la['generator_kwh']   - $lfp['generator_kwh'], 2),
            'generator_hours_saved' => $la['generator_hours']        - $lfp['generator_hours'],
            'fuel_cost_saved'       => ($la['fuel_cost'] !== null && $lfp['fuel_cost'] !== null)
                                        ? round($la['fuel_cost'] - $lfp['fuel_cost'], 2)
                                        : null,
        ];

        return response()->json([
            'nominal_kwh'    => round($nominalKwh, 2),
            'month'          => $month,
            'day_type'       => $dayType,
            'comparison'     => $comparison,
            'delta'          => $delta,
            'currency'       => $project->currency_symbol ?? '$',
            'has_fuel_data'  => $genLine !== null,
        ]);
    }
}
