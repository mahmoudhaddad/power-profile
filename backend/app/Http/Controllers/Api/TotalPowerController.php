<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\BuildingComponent;
use App\Models\Floor;
use App\Models\FloorComponent;
use App\Models\Project;
use App\Models\Room;
use App\Models\RoomComponent;
use App\Services\SocketDemandService;
use Illuminate\Http\Request;

class TotalPowerController extends Controller
{
    public function __construct(private SocketDemandService $sockets) {}

    private function projectSources(Project $project): array
    {
        $buildingsArea = $project->buildings()->sum('area');
        $solarComputed = $buildingsArea * 0.17 * 1000 * 0.75;

        return [
            'solar_computed' => round($solarComputed, 2),
        ];
    }

    /**
     * Sum power for a component query with group-max optimisation.
     *
     * Returns:
     *   va / w               – optimised totals
     *   max_va / max_w       – unoptimised totals (sum of every component)
     *   {priority}_va/w      – optimised per priority (critical/essential/normal)
     *   {priority}_max_va/w  – unoptimised per priority
     *
     * withPriority=false → all components are treated as 'normal'.
     */
    private function sumPowerWithGroups($query, string $entityKey, bool $withPriority = false): array
    {
        $columns = ['power', 'power_factor', 'quantity', 'group_name', $entityKey];
        if ($withPriority) {
            $columns[] = 'priority';
        }

        $components = $query->get($columns);

        $pKeys = ['critical', 'essential', 'normal'];

        $maxVA   = 0.0;
        $maxW    = 0.0;
        $vaTotal = 0.0;
        $wTotal  = 0.0;

        $maxByP  = array_fill_keys($pKeys, ['va' => 0.0, 'w' => 0.0]);
        $ungrpByP = array_fill_keys($pKeys, ['va' => 0.0, 'w' => 0.0]);
        $groups  = [];

        foreach ($components as $c) {
            $va       = (float) $c->power * (int) $c->quantity;
            $w        = (float) $c->power * (float) ($c->power_factor ?? 1) * (int) $c->quantity;
            $priority = $withPriority ? ($c->priority ?? 'normal') : 'normal';

            // Unoptimised running totals
            $maxVA                   += $va;
            $maxW                    += $w;
            $maxByP[$priority]['va'] += $va;
            $maxByP[$priority]['w']  += $w;

            if (!$c->group_name) {
                $vaTotal += $va;
                $wTotal  += $w;
                $ungrpByP[$priority]['va'] += $va;
                $ungrpByP[$priority]['w']  += $w;
            } else {
                $key = $c->{$entityKey} . '|' . $c->group_name;
                if (!isset($groups[$key]) || $va > $groups[$key]['va']) {
                    $groups[$key] = ['va' => $va, 'w' => $w, 'priority' => $priority];
                }
            }
        }

        // Group winners contribute to optimised totals
        $grpByP = array_fill_keys($pKeys, ['va' => 0.0, 'w' => 0.0]);
        foreach ($groups as $g) {
            $vaTotal               += $g['va'];
            $wTotal                += $g['w'];
            $p = $g['priority'];
            $grpByP[$p]['va']      += $g['va'];
            $grpByP[$p]['w']       += $g['w'];
        }

        $result = [
            'va'     => round($vaTotal, 2),
            'w'      => round($wTotal,  2),
            'max_va' => round($maxVA,   2),
            'max_w'  => round($maxW,    2),
        ];

        foreach ($pKeys as $p) {
            $optVA = $ungrpByP[$p]['va'] + $grpByP[$p]['va'];
            $optW  = $ungrpByP[$p]['w']  + $grpByP[$p]['w'];
            $result["{$p}_va"]     = round($optVA,            2);
            $result["{$p}_w"]      = round($optW,             2);
            $result["{$p}_max_va"] = round($maxByP[$p]['va'], 2);
            $result["{$p}_max_w"]  = round($maxByP[$p]['w'],  2);
        }

        return $result;
    }

    /** Aggregate multiple sumPowerWithGroups results into one. */
    private function mergePower(array ...$parts): array
    {
        $keys = ['va', 'w', 'max_va', 'max_w',
                 'critical_va', 'critical_w', 'critical_max_va', 'critical_max_w',
                 'essential_va', 'essential_w', 'essential_max_va', 'essential_max_w',
                 'normal_va', 'normal_w', 'normal_max_va', 'normal_max_w'];

        $result = array_fill_keys($keys, 0.0);
        foreach ($parts as $part) {
            foreach ($keys as $k) {
                $result[$k] += $part[$k] ?? 0.0;
            }
        }
        return $result;
    }

    public function project(Request $request, Project $project)
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $own      = $this->sumPowerWithGroups($project->components(),  'project_id',  true);
        $building = $this->sumPowerWithGroups(
            BuildingComponent::whereHas('building', fn($q) => $q->where('project_id', $project->id)),
            'building_id', true
        );
        $floor    = $this->sumPowerWithGroups(
            FloorComponent::whereHas('floor.building', fn($q) => $q->where('project_id', $project->id)),
            'floor_id', true
        );
        $room     = $this->sumPowerWithGroups(
            RoomComponent::whereHas('room.floor.building', fn($q) => $q->where('project_id', $project->id)),
            'room_id'
        );

        $comp = $this->mergePower($own, $building, $floor, $room);
        $sd   = $this->sockets->projectResult($project);

        $socketDemand    = $sd['demand_va'];
        $socketConnected = $sd['connected_va'];

        return response()->json(array_merge([
            'total_va'         => round($comp['va']     + $socketDemand,    2),
            'total'            => round($comp['w']      + $socketDemand,    2),
            'max_va'           => round($comp['max_va'] + $socketConnected, 2),
            'max_w'            => round($comp['max_w']  + $socketConnected, 2),

            'critical_va'      => $comp['critical_va'],
            'critical_w'       => $comp['critical_w'],
            'critical_max_va'  => $comp['critical_max_va'],
            'critical_max_w'   => $comp['critical_max_w'],
            'essential_va'     => $comp['essential_va'],
            'essential_w'      => $comp['essential_w'],
            'essential_max_va' => $comp['essential_max_va'],
            'essential_max_w'  => $comp['essential_max_w'],
            'normal_va'        => $comp['normal_va'],
            'normal_w'         => $comp['normal_w'],
            'normal_max_va'    => $comp['normal_max_va'],
            'normal_max_w'     => $comp['normal_max_w'],

            'socket_demand_va'    => $socketDemand,
            'socket_connected_va' => $socketConnected,

            // Legacy breakdown fields kept for compatibility
            'own'         => $own['w'],      'own_va'      => $own['va'],
            'building'    => $building['w'], 'building_va' => $building['va'],
            'floor'       => $floor['w'],    'floor_va'    => $floor['va'],
            'room'        => $room['w'],     'room_va'     => $room['va'],
            'critical'    => $comp['critical_w'],
        ], $this->projectSources($project)));
    }

    public function building(Request $request, Building $building)
    {
        if (! $building->project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $own   = $this->sumPowerWithGroups($building->components(), 'building_id', true);
        $floor = $this->sumPowerWithGroups(
            FloorComponent::whereHas('floor', fn($q) => $q->where('building_id', $building->id)),
            'floor_id', true
        );
        $room  = $this->sumPowerWithGroups(
            RoomComponent::whereHas('room.floor', fn($q) => $q->where('building_id', $building->id)),
            'room_id'
        );

        $comp = $this->mergePower($own, $floor, $room);
        $sd   = $this->sockets->buildingResult($building);

        $socketDemand    = $sd['demand_va'];
        $socketConnected = $sd['connected_va'];

        return response()->json(array_merge([
            'total_va'         => round($comp['va']     + $socketDemand,    2),
            'total'            => round($comp['w']      + $socketDemand,    2),
            'max_va'           => round($comp['max_va'] + $socketConnected, 2),
            'max_w'            => round($comp['max_w']  + $socketConnected, 2),

            'critical_va'      => $comp['critical_va'],
            'critical_w'       => $comp['critical_w'],
            'critical_max_va'  => $comp['critical_max_va'],
            'critical_max_w'   => $comp['critical_max_w'],
            'essential_va'     => $comp['essential_va'],
            'essential_w'      => $comp['essential_w'],
            'essential_max_va' => $comp['essential_max_va'],
            'essential_max_w'  => $comp['essential_max_w'],
            'normal_va'        => $comp['normal_va'],
            'normal_w'         => $comp['normal_w'],
            'normal_max_va'    => $comp['normal_max_va'],
            'normal_max_w'     => $comp['normal_max_w'],

            'socket_demand_va'           => $socketDemand,
            'socket_connected_va'        => $socketConnected,
            'socket_sum_floor_demand_va' => $sd['sum_floor_demand_va'],
            'socket_coincidence_factor'  => $sd['coincidence_factor'],

            'own'      => $own['w'],   'own_va'   => $own['va'],
            'floor'    => $floor['w'], 'floor_va' => $floor['va'],
            'room'     => $room['w'],  'room_va'  => $room['va'],
        ], $this->projectSources($building->project)));
    }

    public function floor(Request $request, Floor $floor)
    {
        if (! $floor->building->project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $own  = $this->sumPowerWithGroups($floor->components(), 'floor_id', true);
        $room = $this->sumPowerWithGroups(
            RoomComponent::whereHas('room', fn($q) => $q->where('floor_id', $floor->id)),
            'room_id'
        );

        $comp = $this->mergePower($own, $room);
        $sd   = $this->sockets->floorResult($floor);

        $socketDemand    = $sd['demand_va'];
        $socketConnected = $sd['connected_va'];

        return response()->json(array_merge([
            'total_va'         => round($comp['va']     + $socketDemand,    2),
            'total'            => round($comp['w']      + $socketDemand,    2),
            'max_va'           => round($comp['max_va'] + $socketConnected, 2),
            'max_w'            => round($comp['max_w']  + $socketConnected, 2),

            'critical_va'      => $comp['critical_va'],
            'critical_w'       => $comp['critical_w'],
            'critical_max_va'  => $comp['critical_max_va'],
            'critical_max_w'   => $comp['critical_max_w'],
            'essential_va'     => $comp['essential_va'],
            'essential_w'      => $comp['essential_w'],
            'essential_max_va' => $comp['essential_max_va'],
            'essential_max_w'  => $comp['essential_max_w'],
            'normal_va'        => $comp['normal_va'],
            'normal_w'         => $comp['normal_w'],
            'normal_max_va'    => $comp['normal_max_va'],
            'normal_max_w'     => $comp['normal_max_w'],

            'socket_outlets'      => $sd['outlets'],
            'socket_connected_va' => $socketConnected,
            'socket_demand_va'    => $socketDemand,

            'own'  => $own['w'],  'own_va'  => $own['va'],
            'room' => $room['w'], 'room_va' => $room['va'],
        ], $this->projectSources($floor->building->project)));
    }

    public function room(Request $request, Room $room)
    {
        if (! $room->floor->building->project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $own = $this->sumPowerWithGroups($room->components(), 'room_id');
        $sd  = $this->sockets->roomResult($room);

        $socketDemand    = $sd['demand_va'];
        $socketConnected = $sd['connected_va'];

        return response()->json(array_merge([
            'total_va'         => round($own['va']     + $socketDemand,    2),
            'total'            => round($own['w']      + $socketDemand,    2),
            'max_va'           => round($own['max_va'] + $socketConnected, 2),
            'max_w'            => round($own['max_w']  + $socketConnected, 2),

            'critical_va'      => $own['critical_va'],
            'critical_w'       => $own['critical_w'],
            'critical_max_va'  => $own['critical_max_va'],
            'critical_max_w'   => $own['critical_max_w'],
            'essential_va'     => $own['essential_va'],
            'essential_w'      => $own['essential_w'],
            'essential_max_va' => $own['essential_max_va'],
            'essential_max_w'  => $own['essential_max_w'],
            'normal_va'        => $own['normal_va'],
            'normal_w'         => $own['normal_w'],
            'normal_max_va'    => $own['normal_max_va'],
            'normal_max_w'     => $own['normal_max_w'],

            'socket_outlets'      => $sd['outlets'],
            'socket_connected_va' => $socketConnected,
            'socket_demand_va'    => $socketDemand,

            'own'    => $own['w'], 'own_va' => $own['va'],
        ], $this->projectSources($room->floor->building->project)));
    }
}
