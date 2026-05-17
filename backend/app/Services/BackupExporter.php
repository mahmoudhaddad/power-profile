<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Project;
use App\Models\Room;

class BackupExporter
{
    public function exportProject(Project $project): array
    {
        return [
            'name'            => $project->name,
            'building_type'   => $project->building_type,
            'solar_power'     => $project->solar_power,
            'generator_power' => $project->generator_power,
            'utility_lines'   => $project->utilityLines()->get(['name', 'power', 'phases'])->toArray(),
            'generator_lines' => $project->generatorLines()->get(['name', 'power', 'phases'])->toArray(),
            'components'      => $this->exportComponents($project->components()->with('componentType')->get()),
            'sockets'         => $project->sockets()->get(['phase_type', 'power', 'quantity'])->toArray(),
            'buildings'       => $project->buildings()->get()
                ->map(fn($b) => $this->exportBuilding($b))->toArray(),
        ];
    }

    public function exportBuilding(Building $building): array
    {
        return [
            'name'            => $building->name,
            'area'            => $building->area,
            'utility_lines'   => $building->utilityLines()->get(['name', 'power', 'phases'])->toArray(),
            'generator_lines' => $building->generatorLines()->get(['name', 'power', 'phases'])->toArray(),
            'components'      => $this->exportComponents($building->components()->with('componentType')->get()),
            'sockets'         => $building->sockets()->get(['phase_type', 'power', 'quantity'])->toArray(),
            'floors'          => $building->floors()->get()
                ->map(fn($f) => $this->exportFloor($f))->toArray(),
        ];
    }

    public function exportFloor(Floor $floor): array
    {
        return [
            'name'            => $floor->name,
            'area'            => $floor->area,
            'utility_lines'   => $floor->utilityLines()->get(['name', 'power', 'phases'])->toArray(),
            'generator_lines' => $floor->generatorLines()->get(['name', 'power', 'phases'])->toArray(),
            'components'      => $this->exportComponents($floor->components()->with('componentType')->get()),
            'sockets'         => $floor->sockets()->get(['phase_type', 'power', 'quantity'])->toArray(),
            'rooms'           => $floor->rooms()->get()
                ->map(fn($r) => $this->exportRoom($r))->toArray(),
        ];
    }

    public function exportRoom(Room $room): array
    {
        return [
            'name'            => $room->name,
            'area'            => $room->area,
            'utility_lines'   => $room->utilityLines()->get(['name', 'power', 'phases'])->toArray(),
            'generator_lines' => $room->generatorLines()->get(['name', 'power', 'phases'])->toArray(),
            'components'      => $room->components()->with('componentType')->get()
                ->map(fn($c) => [
                    'component_name' => $c->componentType->name,
                    'power'          => $c->power,
                    'phases'         => $c->phases,
                    'power_factor'   => $c->power_factor,
                    'quantity'       => $c->quantity,
                ])->toArray(),
            'sockets'         => $room->sockets()->get(['phase_type', 'power', 'quantity'])->toArray(),
        ];
    }

    private function exportComponents($components): array
    {
        return $components->map(fn($c) => [
            'component_name' => $c->componentType->name,
            'power'          => $c->power,
            'phases'         => $c->phases,
            'power_factor'   => $c->power_factor,
            'quantity'       => $c->quantity,
            'group_name'     => $c->group_name,
            'priority'       => $c->priority,
            'needs_socket'      => $c->needs_socket,
            'usage_season'      => $c->usage_season,
            'usage_day_type'    => $c->usage_day_type,
            'usage_time_intervals' => $c->usage_time_intervals,
        ])->toArray();
    }
}
