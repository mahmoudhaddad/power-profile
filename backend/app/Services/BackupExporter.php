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
            'solar_systems'   => $project->solarSystems()->get()->map(fn($s) => [
                'name'        => $s->name,
                'capacity_kw' => $s->capacity_kw,
                'is_active'   => $s->is_active,
                'notes'       => $s->notes,
            ])->toArray(),
            'batteries'       => $project->batteries()->get()->map(fn($b) => [
                'name'                  => $b->name,
                'chemistry'             => $b->chemistry,
                'nominal_voltage_v'     => $b->nominal_voltage_v,
                'capacity_ah_per_unit'  => $b->capacity_ah_per_unit,
                'quantity'              => $b->quantity,
                'series_count'          => $b->series_count,
                'parallel_count'        => $b->parallel_count,
                'installation_date'     => $b->getRawOriginal('installation_date'),
                'depth_of_discharge'    => $b->depth_of_discharge,
                'round_trip_efficiency' => $b->round_trip_efficiency,
                'c_rate_charge'         => $b->c_rate_charge,
                'c_rate_discharge'      => $b->c_rate_discharge,
                'rated_cycle_life'      => $b->rated_cycle_life,
                'current_soc'           => $b->current_soc,
                'is_active'             => $b->is_active,
                'notes'                 => $b->notes,
                // Store the solar system name so restore can re-link by name match
                'solar_system_name'     => $b->solarSystem?->name,
            ])->toArray(),
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
            'components'      => $this->exportComponents($room->components()->with('componentType')->get()),
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
