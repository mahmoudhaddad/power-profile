<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\DiversityFactorService;
use App\Services\SolarIrradianceService;
use App\Services\SourceDispatchService;
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

        // ── 4. Active batteries (fetched once, used in every day simulation) ───
        $batteries = $project->batteries()->where('is_active', true)->get();
        $battPass  = $batteries->isNotEmpty() ? $batteries : null;

        // ── 5. Per-day profiles: one entry per day of the week ────────────────
        // Each day simulation starts from batteries' stored current_soc.
        $days = [];
        foreach (self::ALL_DAYS as $dayName) {
            $loadMax = $this->buildHourlyW($components, 'max',       $dayName, $month, false);
            $loadOpt = $this->buildHourlyW($components, 'optimized', $dayName, $month, true);

            $days[$dayName] = [
                'load_max'           => $loadMax,
                'load_optimized'     => $loadOpt,
                'hourly_kvar'        => $this->buildHourlyKvar($components, $dayName, $month),
                'dispatch_max'       => $this->dispatchSvc->dispatch($loadMax, $solarProfile, $utilCapW, $genCapW, $battPass, $solarCapacityW, $solarSystems->isNotEmpty() ? $solarSystems : null),
                'dispatch_optimized' => $this->dispatchSvc->dispatch($loadOpt, $solarProfile, $utilCapW, $genCapW, $battPass, $solarCapacityW, $solarSystems->isNotEmpty() ? $solarSystems : null),
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
                'va'        => $va,
                'peak_w'    => $va * $pf,  // undiversified P = S × PF
                'df'        => $df,
                'pf'        => $pf,
                'intervals' => $c->usage_time_intervals ?? [['start' => '08:00', 'end' => '18:00']],
                'season'    => $c->usage_season  ?? 'all',
                'day_type'  => $c->usage_day_type ?? 'all',
                'priority'  => $c->priority,
                'group_key' => $c->group_name ? ($key . '|' . $c->{$key} . '|' . $c->group_name) : null,
                'work_days' => $workDays,
                'seasons'   => $seasons,
            ];
        }
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
}
