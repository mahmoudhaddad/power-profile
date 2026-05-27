<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\SolarIrradianceService;
use Illuminate\Http\Request;

class LoadProfileController extends Controller
{
    private const DEFAULT_WORK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    // IEC 60364-8-1 diversity factors — must match TotalPowerController constants.
    private const DF_FLOOR    = 0.9;
    private const DF_BUILDING = 0.8;
    private const DF_PROJECT  = 0.7;

    public function project(Request $request, Project $project, SolarIrradianceService $solarService)
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $pDays    = $project->work_days;
        $pSeasons = $project->working_season_intervals;

        $result = [];

        // Project-level components: no diversity reduction at their own level.
        $this->addComponents($result, $project->components, 'project_id', $pDays, $pSeasons, 1.0);

        $buildings = $project->buildings()->with([
            'components',
            'floors.components',
            'floors.rooms.components',
        ])->get();

        foreach ($buildings as $building) {
            $bDays    = $building->work_days ?? $pDays;
            $bSeasons = $building->working_season_intervals ?? $pSeasons;

            // Building-level: aggregated to project level, so reduced by DF_PROJECT.
            $this->addComponents($result, $building->components, 'building_id', $bDays, $bSeasons,
                self::DF_PROJECT);

            foreach ($building->getRelation('floors') as $floor) {
                $fDays    = $floor->work_days ?? $bDays;
                $fSeasons = $floor->working_season_intervals ?? $bSeasons;

                // Floor-level: passes through building (×DF_BUILDING) then project (×DF_PROJECT).
                $this->addComponents($result, $floor->components, 'floor_id', $fDays, $fSeasons,
                    self::DF_BUILDING * self::DF_PROJECT);

                foreach ($floor->rooms as $room) {
                    $rDays    = $room->work_days ?? $fDays;
                    $rSeasons = $room->working_season_intervals ?? $fSeasons;

                    // Room-level: passes through floor (×DF_FLOOR), building (×DF_BUILDING), project (×DF_PROJECT).
                    $this->addComponents($result, $room->components, 'room_id', $rDays, $rSeasons,
                        self::DF_FLOOR * self::DF_BUILDING * self::DF_PROJECT);
                }
            }
        }

        // Solar generation profile
        $panelCapacityKw = (float) ($project->solar_power ?? 0.0);
        $month           = (int) date('n');
        $hourlySolarW    = $solarService->getHourlyOutputWatts(
            $project->location_lat ? (float) $project->location_lat : null,
            $project->location_lng ? (float) $project->location_lng : null,
            $month,
            $panelCapacityKw
        );
        $hourlySolarKw = array_map(fn($w) => round($w / 1000, 4), $hourlySolarW);

        return response()->json([
            'components'       => $result,
            'hourly_kw'        => $this->computeHourlyW($result),
            'hourly_kvar'      => $this->computeHourlyKvar($result),
            'hourly_solar_kw'  => $hourlySolarKw,
            'solar_data_source' => $solarService->getDataSource(),
        ]);
    }

    private function addComponents(array &$result, $components, string $entityKey, ?array $workDays, ?array $workSeasons, float $dfMultiplier = 1.0): void
    {
        $groups    = [];
        $ungrouped = [];

        foreach ($components as $c) {
            $pf = max((float)($c->power_factor ?? 1.0), 0.01);
            $va = (float) $c->power * (int) $c->quantity;        // S = VA_rated × qty (power stores VA)

            if (! $c->group_name) {
                $ungrouped[] = $c;
            } else {
                $key = $c->{$entityKey} . '|' . $c->group_name;
                if (! isset($groups[$key]) || $va > $groups[$key]['va']) {
                    $groups[$key] = ['va' => $va, 'component' => $c];
                }
            }
        }

        foreach ($ungrouped as $c) {
            $result[] = $this->fmt($c, $workDays, $workSeasons, $dfMultiplier);
        }
        foreach ($groups as $g) {
            $result[] = $this->fmt($g['component'], $workDays, $workSeasons, $dfMultiplier);
        }
    }

    private function fmt($component, ?array $workDays, ?array $workSeasons, float $dfMultiplier = 1.0): array
    {
        $pf          = max(0.01, (float) ($component->power_factor ?? 1.0));
        $effectiveDf = ($component->priority === 'critical') ? 1.0 : $dfMultiplier;
        $peakW       = (float) $component->power * $pf * (int) $component->quantity * $effectiveDf;

        return [
            'peak_w'                          => round($peakW, 4),
            'power_factor'                    => max(0.01, min(1.0, (float) ($component->power_factor ?? 1.0))),
            'usage_time_intervals'            => $component->usage_time_intervals ?? [['start' => '08:00', 'end' => '18:00']],
            'usage_season'                    => $component->usage_season   ?? 'all',
            'usage_day_type'                  => $component->usage_day_type ?? 'all',
            'priority'                        => $component->priority,
            'entity_work_days'                => $workDays ?? self::DEFAULT_WORK_DAYS,
            'entity_working_season_intervals' => $workSeasons,
        ];
    }

    /** Hourly real power profile [kW] — sums peak_w per active hour. */
    private function computeHourlyW(array $components): array
    {
        $hourlyP = array_fill(0, 24, 0.0);

        foreach ($components as $c) {
            $peakW = (float) ($c['peak_w'] ?? 0.0);
            if ($peakW <= 0) continue;

            if (($c['priority'] ?? null) === 'critical') {
                for ($h = 0; $h < 24; $h++) { $hourlyP[$h] += $peakW; }
                continue;
            }

            foreach ($c['usage_time_intervals'] as $interval) {
                $startH = max(0, min(23, (int) substr((string) ($interval['start'] ?? '0:00'), 0, 2)));
                $endH   = max(0, min(24, (int) substr((string) ($interval['end']   ?? '0:00'), 0, 2)));

                if ($startH < $endH) {
                    for ($h = $startH; $h < $endH; $h++) { $hourlyP[$h] += $peakW; }
                } elseif ($startH > $endH) {
                    for ($h = $startH; $h < 24; $h++) { $hourlyP[$h] += $peakW; }
                    for ($h = 0; $h < $endH; $h++)    { $hourlyP[$h] += $peakW; }
                }
            }
        }

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = round($hourlyP[$h] / 1000, 2);
        }
        return $out;
    }

    /** Hourly reactive power profile [kVAR] — Q_h = Σ P_i × tan(arccos(PF_i)) per active hour. */
    private function computeHourlyKvar(array $components): array
    {
        // Sum individual Q_i = P_i × tan(arccos(PF_i)) per hour [VAR].
        // Summing Q directly is correct because all reactive power is lagging.
        $hourlyQ = array_fill(0, 24, 0.0);

        foreach ($components as $c) {
            $peakW = (float) ($c['peak_w'] ?? 0.0);
            if ($peakW <= 0) continue;
            $pf = max(0.01, min(1.0, (float) ($c['power_factor'] ?? 1.0)));
            if ($pf >= 1.0) continue;

            $qi = $peakW * tan(acos($pf));

            if (($c['priority'] ?? null) === 'critical') {
                for ($h = 0; $h < 24; $h++) { $hourlyQ[$h] += $qi; }
                continue;
            }

            foreach ($c['usage_time_intervals'] as $interval) {
                $startH = (int) substr((string) ($interval['start'] ?? '0:00'), 0, 2);
                $endH   = (int) substr((string) ($interval['end']   ?? '0:00'), 0, 2);
                $startH = max(0, min(23, $startH));
                $endH   = max(0, min(24, $endH));

                if ($startH < $endH) {
                    for ($h = $startH; $h < $endH; $h++) { $hourlyQ[$h] += $qi; }
                } elseif ($startH > $endH) {
                    for ($h = $startH; $h < 24; $h++) { $hourlyQ[$h] += $qi; }
                    for ($h = 0; $h < $endH; $h++)    { $hourlyQ[$h] += $qi; }
                }
            }
        }

        $hourlyKvar = [];
        for ($h = 0; $h < 24; $h++) {
            $hourlyKvar[] = round($hourlyQ[$h] / 1000, 2);
        }
        return $hourlyKvar;
    }
}
