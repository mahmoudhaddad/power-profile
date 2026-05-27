<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\SolarIrradianceService;
use App\Services\SourceDispatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    private const DEFAULT_WORK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    // IEC 60364-8-1 diversity factors — must match TotalPowerController and LoadProfileController.
    private const DF_FLOOR    = 0.9;
    private const DF_BUILDING = 0.8;
    private const DF_PROJECT  = 0.7;

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
        // Specific calendar day (1-31). Clamped to days-in-month for 2023.
        $dayNum  = max(1, min(31, (int) $request->query('day', 15)));
        $dayNum  = min($dayNum, (int) date('t', mktime(0, 0, 0, $month, 1, 2023)));

        // ── 1. Collect all components with schedule metadata ──────────────────
        $components = $this->collectComponents($project);

        // ── 2. Build 24-hour load profiles (W) ───────────────────────────────
        $loadMax = $this->buildHourlyW($components, 'max',       $dayType, $month, false);
        $loadOpt = $this->buildHourlyW($components, 'optimized', $dayType, $month, true);

        Log::debug('Schedule profile peaks', [
            'load_max_peak_w'       => max($loadMax),
            'load_optimized_peak_w' => max($loadOpt),
            'df_applied'            => true,
        ]);

        // ── 3. Solar profile ──────────────────────────────────────────────────
        $solarMode = $project->solar_source ?? 'max';

        if ($solarMode === 'existing') {
            // User-declared installed capacity: project-level + sum of buildings
            $solarCapacityW = (float) ($project->existing_solar_power ?? 0)
                            + (float) $project->buildings()->sum('existing_solar_power');
        } else {
            // 'max' mode: auto-compute from roof area (matches frontend formula)
            // area_m² × 17% coverage × 1 000 W/m² STC × 0.75 PR = W
            $totalAreaM2    = (float) $project->buildings()->sum('area');
            $solarCapacityW = $totalAreaM2 * 0.17 * 1000 * 0.75;
        }

        $lat = $project->location_lat !== null ? (float) $project->location_lat : null;
        $lng = $project->location_lng !== null ? (float) $project->location_lng : null;

        // NASA POWER first (uses exact calendar day), static PSH fallback
        $solarProfile = $this->solarSvc->getHourlyOutputWatts(
            $lat, $lng, $month, $solarCapacityW / 1000.0,
            performanceRatio: 0.80, day: $dayNum
        );

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

        $hourlyKvar = $this->buildHourlyKvar($components, $dayType, $month);

        return response()->json([
            'month'           => $month,
            'month_name'      => self::MONTH_NAMES[$month],
            'day'             => $dayNum,
            'day_type'        => $dayType,
            'location'        => [
                'lat'  => $lat,
                'lng'  => $lng,
                'name' => $project->location_name,
            ],
            'sunrise_hour'    => $sunInfo['sunrise'] ?? null,
            'sunset_hour'     => $sunInfo['sunset']  ?? null,
            'peak_sun_hours'  => $psh,
            'solar_capacity_w'     => $solarCapacityW,
            'solar_data_source'    => $this->solarSvc->getDataSource(),
            'utility_capacity_va'  => $utilCapVA,
            'generator_capacity_va'=> $genCapVA,
            'load_max'         => $loadMax,
            'load_optimized'   => $loadOpt,
            'solar'            => $solarProfile,
            'dispatch_max'     => $dispatchMax,
            'dispatch_optimized' => $dispatchOpt,
            'hourly_kvar'      => $hourlyKvar,
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
            // Building → project: ×DF_PROJECT
            $this->extractRaw($result, $building->components, 'building_id', $bDays, $bSeasons,
                self::DF_PROJECT);

            foreach ($building->getRelation('floors') as $floor) {
                $fDays    = $floor->work_days    ?? $bDays;
                $fSeasons = $floor->working_season_intervals ?? $bSeasons;
                // Floor → building → project: ×DF_BUILDING × DF_PROJECT
                $this->extractRaw($result, $floor->components, 'floor_id', $fDays, $fSeasons,
                    self::DF_BUILDING * self::DF_PROJECT);

                foreach ($floor->rooms as $room) {
                    $rDays    = $room->work_days    ?? $fDays;
                    $rSeasons = $room->working_season_intervals ?? $fSeasons;
                    // Room → floor → building → project: ×DF_FLOOR × DF_BUILDING × DF_PROJECT
                    $this->extractRaw($result, $room->components, 'room_id', $rDays, $rSeasons,
                        self::DF_FLOOR * self::DF_BUILDING * self::DF_PROJECT);
                }
            }
        }

        return $result;
    }

    private function extractRaw(array &$out, $components, string $key, ?array $workDays, ?array $seasons, float $df = 1.0): void
    {
        foreach ($components as $c) {
            $pf    = max(0.01, (float) ($c->power_factor ?? 1));
            $out[] = [
                'peak_w'    => (float) $c->power * $pf * (int) $c->quantity, // undiversified P = VA_rated × PF × qty
                'df'        => $df,      // compounded IEC diversity factor for this hierarchy level
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

    private function buildHourlyW(array $components, string $mode, string $dayType, int $month, bool $applyDiversity = false): array
    {
        if ($mode === 'optimized') {
            // Group-max: within a group, keep only the highest-VA (peak_w) component.
            // Selection is based on raw undiversified peak_w; DF is applied after selection.
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
            $isCritical  = ($c['priority'] === 'critical');
            // Critical loads are never diversified; max profile also uses df=1.0 for everything.
            $effectiveDf = ($applyDiversity && ! $isCritical) ? (float) $c['df'] : 1.0;
            $peakW       = $c['peak_w'] * $effectiveDf;

            if ($isCritical) {
                // Critical loads run 24/7 at full value — no schedule filtering.
                for ($h = 0; $h < 24; $h++) { $profile[$h] += $peakW; }
                continue;
            }

            if (! $this->activeInMonth($c['seasons'], $month)) continue;
            if (! $this->componentSeasonOk($c['season'],   $month)) continue;
            if (! $this->dayTypeOk($c['work_days'], $c['day_type'], $dayType)) continue;

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

    private function dayTypeOk(?array $workDays, string $compDayType, string $requestedDayType): bool
    {
        $weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
        $weekend  = ['saturday', 'sunday'];

        // DB stores 'weekday'; guard against old 'workday' entries too.
        $isWeekdayComp = in_array($compDayType, ['weekday', 'workday'], true);

        if ($requestedDayType === 'workday') {
            // null work_days → entity defaults to Mon-Fri (most entities are weekday ops)
            $effective = $workDays ?? $weekdays;
            if (! array_intersect($effective, $weekdays)) return false;
            if ($compDayType === 'weekend')               return false;
        } elseif ($requestedDayType === 'weekend') {
            // null work_days → entity not explicitly configured → runs all days
            // Only block if entity was explicitly set to exclude weekends
            if ($workDays !== null && ! array_intersect($workDays, $weekend)) return false;
            // Weekday-tagged components don't run on weekends
            if ($isWeekdayComp) return false;
        }
        return true;
    }

    private function dec(string $t): float
    {
        [$h, $m] = array_map('intval', explode(':', $t));
        return $h + $m / 60.0;
    }

    private function buildHourlyKvar(array $components, string $dayType, int $month): array
    {
        $hourlyQ = array_fill(0, 24, 0.0);

        foreach ($components as $c) {
            $peakW = (float) $c['peak_w'];
            if ($peakW <= 0) continue;
            $pf = max(0.01, min(1.0, (float) ($c['pf'] ?? 1.0)));
            if ($pf >= 1.0) continue;

            $isCritical  = ($c['priority'] === 'critical');
            // kvar tracks the diversified (optimized) reactive profile to match load_optimized.
            $effectiveDf = $isCritical ? 1.0 : (float) $c['df'];
            $qi          = $peakW * $effectiveDf * tan(acos($pf));

            if ($isCritical) {
                for ($h = 0; $h < 24; $h++) { $hourlyQ[$h] += $qi; }
                continue;
            }

            if (! $this->activeInMonth($c['seasons'], $month))                 continue;
            if (! $this->componentSeasonOk($c['season'], $month))              continue;
            if (! $this->dayTypeOk($c['work_days'], $c['day_type'], $dayType)) continue;

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
