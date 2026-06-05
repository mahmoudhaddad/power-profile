<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\CostSignalService;
use App\Services\DiversityFactorService;
use App\Services\SolarIrradianceService;
use App\Services\SourceDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/projects/{project}/cost-signal?month=6&day_type=monday
 *
 * 1. Builds the 24-hour load and solar profiles for the requested month/day-type
 *    (same methodology as ScheduleController — optimised diversity-weighted profile).
 * 2. Calls SourceDispatchService::dispatch() ONCE to get the baseline hourly split.
 * 3. Passes the dispatch result directly into CostSignalService — no second simulation.
 * 4. Returns the cost signal alongside dispatch totals for context.
 */
class CostSignalController extends Controller
{
    private const DF_PROJECT     = 0.7;
    private const DEFAULT_WORK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
    private const ALL_DAYS = [
        'monday','tuesday','wednesday','thursday','friday','saturday','sunday',
    ];

    public function __construct(
        private SolarIrradianceService $solarSvc,
        private SourceDispatchService  $dispatchSvc,
        private CostSignalService      $costSvc,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $month   = max(1, min(12, (int) $request->query('month', now()->month)));
        // Representative day of the week for the dispatch simulation.
        // Defaults to 'monday' (typical weekday). Pass 'saturday' for weekend.
        $dayName = $request->query('day_type', 'monday');
        if (! in_array($dayName, self::ALL_DAYS, true)) {
            $dayName = 'monday';
        }

        // ── 1. Solar capacity ────────────────────────────────────────────────
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
            $lat, $lng, $month, $solarCapacityW / 1000.0,
            performanceRatio: 0.80, day: 15
        );

        // ── 2. Load profile (optimised, diversity-weighted) ──────────────────
        $components = $this->collectComponents($project);
        $loadW      = $this->buildHourlyW($components, $dayName, $month);

        // ── 3. Source capacities ─────────────────────────────────────────────
        $utilCapW = (float) $project->utilityLines()->sum('power')  * 0.8;
        $genCapW  = (float) $project->generatorLines()->sum('power') * 0.8;

        // ── 4. Batteries ─────────────────────────────────────────────────────
        $batteries = $project->batteries()->where('is_active', true)->get();
        $battPass  = $batteries->isNotEmpty() ? $batteries : null;

        // ── 5. Dispatch (ONE call — result handed to cost service) ───────────
        $dispatch = $this->dispatchSvc->dispatch(
            $loadW,
            $solarProfile,
            $utilCapW,
            $genCapW,
            $battPass,
            $solarCapacityW,
            $solarSystems->isNotEmpty() ? $solarSystems : null,
        );

        // ── 6. Cost signal ───────────────────────────────────────────────────
        $costSignal = $this->costSvc->compute(
            $project,
            $month,
            $dispatch,
            $loadW,
            $solarProfile,
        );

        // ── 7. Response ──────────────────────────────────────────────────────
        return response()->json(array_merge($costSignal, [
            'month'         => $month,
            'day_type'      => $dayName,
            'solar_kw'      => array_map(fn($w) => round($w / 1000, 3), $solarProfile),
            'load_kw'       => array_map(fn($w) => round($w / 1000, 3), $loadW),
            'dispatch_kw'   => [
                'solar'                        => array_map(fn($w) => round($w / 1000, 3), $dispatch['solar_used']),
                'battery'                      => array_map(fn($w) => round($w / 1000, 3), $dispatch['battery_discharged'] ?? array_fill(0, 24, 0)),
                'battery_remaining_capacity_kw'=> $dispatch['battery_remaining_capacity_kw'] ?? array_fill(0, 24, 0.0),
                'grid'                         => array_map(fn($w) => round($w / 1000, 3), $dispatch['utility_used']),
                'generator'                    => array_map(fn($w) => round($w / 1000, 3), $dispatch['generator_used']),
                'unmet'                        => array_map(fn($w) => round($w / 1000, 3), $dispatch['unmet']),
            ],
        ]));
    }

    // ── Component collection (same methodology as ScheduleController) ────────

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
            $pf    = max(0.01, (float) ($c->power_factor ?? 1));
            $va    = (float) $c->power * (int) $c->quantity;
            $out[] = [
                'va'        => $va,
                'peak_w'    => $va * $pf,
                'df'        => $df,
                'pf'        => $pf,
                'intervals' => $c->usage_time_intervals ?? [['start' => '08:00', 'end' => '18:00']],
                'season'    => $c->usage_season   ?? 'all',
                'day_type'  => $c->usage_day_type ?? 'all',
                'priority'  => $c->priority,
                'group_key' => $c->group_name ? ($key . '|' . $c->{$key} . '|' . $c->group_name) : null,
                'work_days' => $workDays,
                'seasons'   => $seasons,
            ];
        }
    }

    // ── Build optimised 24-hour load profile in Watts ───────────────────────

    private function buildHourlyW(array $components, string $dayName, int $month): array
    {
        // Group-max: keep only the highest-VA component per group key.
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
        $active = array_merge($ungrouped, array_values($groups));

        $profile = array_fill(0, 24, 0.0);

        foreach ($active as $c) {
            $isCritical  = ($c['priority'] === 'critical');
            $effectiveDf = $isCritical ? 1.0 : (float) $c['df'];
            $peakW       = $c['peak_w'] * $effectiveDf;

            if ($isCritical) {
                for ($h = 0; $h < 24; $h++) { $profile[$h] += $peakW; }
                continue;
            }

            if (! $this->activeInMonth($c['seasons'], $month))             continue;
            if (! $this->seasonOk($c['season'], $month))                   continue;
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

    // ── Schedule helpers (mirrors ScheduleController exactly) ────────────────

    private function activeInMonth(?array $seasonIntervals, int $month): bool
    {
        if (empty($seasonIntervals)) return true;
        $curOrd = $month * 100 + 15;
        foreach ($seasonIntervals as $iv) {
            [$fm, $fd] = array_map('intval', explode('-', $iv['from'] ?? '01-01'));
            [$tm, $td] = array_map('intval', explode('-', $iv['to']   ?? '12-31'));
            $fromOrd   = $fm * 100 + $fd;
            $toOrd     = $tm * 100 + $td;
            if ($fromOrd <= $toOrd) {
                if ($curOrd >= $fromOrd && $curOrd <= $toOrd) return true;
            } else {
                if ($curOrd >= $fromOrd || $curOrd <= $toOrd) return true;
            }
        }
        return false;
    }

    private function seasonOk(string $usageSeason, int $month): bool
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

    private function dayTypeOk(?array $workDays, string $compDayType, string $actualDay): bool
    {
        $wd = $workDays ?? self::DEFAULT_WORK_DAYS;
        $isWorkday = in_array($actualDay, $wd, true);
        if ($compDayType === 'weekend') return ! $isWorkday;
        return $isWorkday;
    }

    private function dec(string $t): float
    {
        [$h, $m] = array_map('intval', explode(':', $t));
        return $h + $m / 60.0;
    }
}
