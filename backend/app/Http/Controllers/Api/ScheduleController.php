<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\SolarIrradianceService;
use App\Services\SourceDispatchService;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    private const DEFAULT_WORK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    private const MONTH_NAMES = [
        1 => 'January',  2 => 'February',  3 => 'March',    4 => 'April',
        5 => 'May',      6 => 'June',       7 => 'July',     8 => 'August',
        9 => 'September',10 => 'October',  11 => 'November',12 => 'December',
    ];

    public function __construct(
        private SolarIrradianceService $solarSvc,
        private SourceDispatchService  $dispatchSvc,
    ) {}

    /**
     * GET /api/projects/{project}/schedule
     * Query params:
     *   month    = 1-12  (default: current month)
     *   day_type = workday | weekend | all  (default: workday)
     */
    public function project(Request $request, Project $project)
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $month   = max(1, min(12, (int) $request->query('month', now()->month)));
        $dayType = $request->query('day_type', 'workday');

        // ── 1. Collect all components with schedule metadata ──────────────────
        $components = $this->collectComponents($project);

        // ── 2. Build 24-hour load profiles (W) ───────────────────────────────
        $loadMax = $this->buildHourlyW($components, 'max',       $dayType, $month);
        $loadOpt = $this->buildHourlyW($components, 'optimized', $dayType, $month);

        // ── 3. Solar profile ──────────────────────────────────────────────────
        $solarMode     = $project->solar_source ?? 'max';
        $solarCapacity = $solarMode === 'existing'
            ? (float) ($project->existing_solar_power ?? 0)
            : (float) ($project->solar_power ?? 0);

        $lat = $project->location_lat !== null ? (float) $project->location_lat : null;
        $lng = $project->location_lng !== null ? (float) $project->location_lng : null;

        $solarProfile = ($lat !== null && $lng !== null && $solarCapacity > 0)
            ? $this->solarSvc->hourlyProfile($lat, $lng, $month, $solarCapacity)
            : array_fill(0, 24, 0.0);

        $sunInfo = $lat !== null
            ? $this->solarSvc->sunriseSunset($lat, $month)
            : ['sunrise' => null, 'sunset' => null];

        $psh = ($lat !== null)
            ? round($this->solarSvc->peakSunHours($lat, $month), 2)
            : null;

        // ── 4. Source capacities (VA stored → W via assumed PF = 0.8 for gen/util) ──
        // Using VA directly here so dispatch comparisons are consistent with load (which is W).
        // For solar, VA ≈ W (PF ≈ 1 for grid-tie inverters).
        $utilCapVA = (float) $project->utilityLines()->sum('power');
        $genCapVA  = (float) $project->generatorLines()->sum('power');
        // Apply standard power factor for utility/generator dispatch:
        $utilCapW  = $utilCapVA * 0.8;
        $genCapW   = $genCapVA  * 0.8;

        // ── 5. Dispatch for both modes ────────────────────────────────────────
        $dispatchMax = $this->dispatchSvc->dispatch($loadMax, $solarProfile, $utilCapW, $genCapW);
        $dispatchOpt = $this->dispatchSvc->dispatch($loadOpt, $solarProfile, $utilCapW, $genCapW);

        return response()->json([
            'month'           => $month,
            'month_name'      => self::MONTH_NAMES[$month],
            'day_type'        => $dayType,
            'location'        => [
                'lat'  => $lat,
                'lng'  => $lng,
                'name' => $project->location_name,
            ],
            'sunrise_hour'    => $sunInfo['sunrise'] ?? null,
            'sunset_hour'     => $sunInfo['sunset']  ?? null,
            'peak_sun_hours'  => $psh,
            'solar_capacity_w'     => $solarCapacity,
            'utility_capacity_va'  => $utilCapVA,
            'generator_capacity_va'=> $genCapVA,
            'load_max'         => $loadMax,
            'load_optimized'   => $loadOpt,
            'solar'            => $solarProfile,
            'dispatch_max'     => $dispatchMax,
            'dispatch_optimized' => $dispatchOpt,
        ]);
    }

    // ── Component collection (mirrors LoadProfileController logic) ────────────

    private function collectComponents(Project $project): array
    {
        $pDays    = $project->work_days;
        $pSeasons = $project->working_season_intervals;
        $result   = [];

        $this->extractRaw($result, $project->components, 'project_id', $pDays, $pSeasons);

        $buildings = $project->buildings()->with([
            'components',
            'floors.components',
            'floors.rooms.components',
        ])->get();

        foreach ($buildings as $building) {
            $bDays    = $building->work_days    ?? $pDays;
            $bSeasons = $building->working_season_intervals ?? $pSeasons;
            $this->extractRaw($result, $building->components, 'building_id', $bDays, $bSeasons);

            foreach ($building->getRelation('floors') as $floor) {
                $fDays    = $floor->work_days    ?? $bDays;
                $fSeasons = $floor->working_season_intervals ?? $bSeasons;
                $this->extractRaw($result, $floor->components, 'floor_id', $fDays, $fSeasons);

                foreach ($floor->rooms as $room) {
                    $rDays    = $room->work_days    ?? $fDays;
                    $rSeasons = $room->working_season_intervals ?? $fSeasons;
                    $this->extractRaw($result, $room->components, 'room_id', $rDays, $rSeasons);
                }
            }
        }

        return $result;
    }

    private function extractRaw(array &$out, $components, string $key, ?array $workDays, ?array $seasons): void
    {
        foreach ($components as $c) {
            $out[] = [
                'peak_w'     => (float) $c->power * (float) ($c->power_factor ?? 1) * (int) $c->quantity,
                'intervals'  => $c->usage_time_intervals ?? [['start' => '08:00', 'end' => '18:00']],
                'season'     => $c->usage_season  ?? 'all',
                'day_type'   => $c->usage_day_type ?? 'all',
                'priority'   => $c->priority,
                'group_key'  => $c->group_name ? ($key . '|' . $c->{$key} . '|' . $c->group_name) : null,
                'work_days'  => $workDays ?? self::DEFAULT_WORK_DAYS,
                'seasons'    => $seasons,
            ];
        }
    }

    // ── 24-hour profile builder ───────────────────────────────────────────────

    private function buildHourlyW(array $components, string $mode, string $dayType, int $month): array
    {
        if ($mode === 'optimized') {
            // Per group, keep only the component with the highest peak_w
            $groups    = [];
            $ungrouped = [];
            foreach ($components as $c) {
                if ($c['group_key'] === null) {
                    $ungrouped[] = $c;
                } else {
                    if (! isset($groups[$c['group_key']]) || $c['peak_w'] > $groups[$c['group_key']]['peak_w']) {
                        $groups[$c['group_key']] = $c;
                    }
                }
            }
            $components = array_merge($ungrouped, array_values($groups));
        }

        $profile = array_fill(0, 24, 0.0);

        foreach ($components as $c) {
            if (! $this->activeInMonth($c['seasons'], $month)) continue;
            if (! $this->componentSeasonOk($c['season'],   $month)) continue;
            if (! $this->dayTypeOk($c['work_days'], $c['day_type'], $dayType)) continue;

            foreach ($c['intervals'] as $iv) {
                $start = $this->dec($iv['start'] ?? '00:00');
                $end   = $this->dec($iv['end']   ?? '23:59');
                if ($end <= $start) $end += 24; // overnight interval

                for ($h = 0; $h < 24; $h++) {
                    $mid = $h + 0.5;
                    // Normal window
                    if ($mid >= $start && $mid < $end) {
                        $profile[$h] += $c['peak_w'];
                    } elseif ($end > 24 && ($mid + 24) >= $start && ($mid + 24) < $end) {
                        // Wrapped overnight: check h in early-morning hours
                        $profile[$h] += $c['peak_w'];
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

    private function dayTypeOk(array $workDays, string $compDayType, string $requestedDayType): bool
    {
        $weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $weekend  = ['saturday', 'sunday'];

        if ($requestedDayType === 'workday') {
            if (! array_intersect($workDays, $weekdays))           return false;
            if ($compDayType === 'weekend')                         return false;
        } elseif ($requestedDayType === 'weekend') {
            if (! array_intersect($workDays, $weekend))            return false;
            if ($compDayType === 'workday')                         return false;
        }
        // 'all' — no filtering
        return true;
    }

    private function dec(string $t): float
    {
        [$h, $m] = array_map('intval', explode(':', $t));
        return $h + $m / 60.0;
    }
}
