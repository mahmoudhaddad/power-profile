<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;

class LoadProfileController extends Controller
{
    private const DEFAULT_WORK_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    public function project(Request $request, Project $project)
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $pDays    = $project->work_days;
        $pSeasons = $project->working_season_intervals;

        $result = [];

        $this->addComponents($result, $project->components, 'project_id', $pDays, $pSeasons);

        $buildings = $project->buildings()->with([
            'components',
            'floors.components',
            'floors.rooms.components',
        ])->get();

        foreach ($buildings as $building) {
            $bDays    = $building->work_days ?? $pDays;
            $bSeasons = $building->working_season_intervals ?? $pSeasons;

            $this->addComponents($result, $building->components, 'building_id', $bDays, $bSeasons);

            foreach ($building->getRelation('floors') as $floor) {
                $fDays    = $floor->work_days ?? $bDays;
                $fSeasons = $floor->working_season_intervals ?? $bSeasons;

                $this->addComponents($result, $floor->components, 'floor_id', $fDays, $fSeasons);

                foreach ($floor->rooms as $room) {
                    $rDays    = $room->work_days ?? $fDays;
                    $rSeasons = $room->working_season_intervals ?? $fSeasons;

                    $this->addComponents($result, $room->components, 'room_id', $rDays, $rSeasons);
                }
            }
        }

        return response()->json(['components' => $result]);
    }

    private function addComponents(array &$result, $components, string $entityKey, ?array $workDays, ?array $workSeasons): void
    {
        $groups    = [];
        $ungrouped = [];

        foreach ($components as $c) {
            $va = (float) $c->power * (int) $c->quantity;

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
            $result[] = $this->fmt($c, $workDays, $workSeasons);
        }
        foreach ($groups as $g) {
            $result[] = $this->fmt($g['component'], $workDays, $workSeasons);
        }
    }

    private function fmt($component, ?array $workDays, ?array $workSeasons): array
    {
        $peakW = (float) $component->power
               * (float) ($component->power_factor ?? 1)
               * (int)   $component->quantity;

        return [
            'peak_w'                          => round($peakW, 4),
            'usage_time_intervals'            => $component->usage_time_intervals ?? [['start' => '08:00', 'end' => '18:00']],
            'usage_season'                    => $component->usage_season   ?? 'all',
            'usage_day_type'                  => $component->usage_day_type ?? 'all',
            'priority'                        => $component->priority,
            'entity_work_days'                => $workDays ?? self::DEFAULT_WORK_DAYS,
            'entity_working_season_intervals' => $workSeasons,
        ];
    }
}
