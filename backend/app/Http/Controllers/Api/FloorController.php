<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use Illuminate\Http\Request;

class FloorController extends Controller
{
    private function getRole(Request $request, Building $building): ?string
    {
        return $building->project->userRole($request->user()->id);
    }

    public function index(Request $request, Building $building)
    {
        $role = $this->getRole($request, $building);
        if (! $role) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $building->load('project');
        $building->project->user_role = $role;

        return response()->json([
            'data'     => $building->floors()->withCount('rooms')->orderBy('created_at', 'desc')->get(),
            'building' => $building,
        ]);
    }

    public function store(Request $request, Building $building)
    {
        $role = $this->getRole($request, $building);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'area'              => 'required|numeric|min:0.01',
            'power_consumption' => 'sometimes|string|max:50',
        ]);

        $floor = $building->floors()->create($data);

        return response()->json(['data' => $floor], 201);
    }

    public function update(Request $request, Building $building, Floor $floor)
    {
        $role = $this->getRole($request, $building);
        if (! in_array($role, ['admin', 'main']) || $floor->building_id !== $building->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'area'              => 'sometimes|numeric|min:0.01',
            'power_consumption' => 'sometimes|string|max:50',
            'solar_power'           => 'sometimes|nullable|numeric|min:0',
            'existing_solar_power'  => 'sometimes|nullable|numeric|min:0',
            'solar_source'          => 'sometimes|in:max,existing',
            'generator_power'       => 'sometimes|nullable|numeric|min:0',
            'work_days'                                 => 'sometimes|nullable|array',
            'work_days.*'                               => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'work_time_intervals'                       => 'sometimes|nullable|array',
            'work_time_intervals.*.start'               => 'required_with:work_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'work_time_intervals.*.end'                 => 'required_with:work_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'working_season_intervals'                  => 'sometimes|nullable|array',
            'working_season_intervals.*.from'           => 'required_with:working_season_intervals|string|regex:/^\d{2}-\d{2}$/',
            'working_season_intervals.*.to'             => 'required_with:working_season_intervals|string|regex:/^\d{2}-\d{2}$/',
        ]);

        $floor->update($data);

        return response()->json(['data' => $floor]);
    }

    public function destroy(Request $request, Building $building, Floor $floor)
    {
        $role = $this->getRole($request, $building);
        if (! in_array($role, ['admin', 'main']) || $floor->building_id !== $building->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $floor->delete();

        return response()->json(['message' => 'Floor deleted.']);
    }
}
