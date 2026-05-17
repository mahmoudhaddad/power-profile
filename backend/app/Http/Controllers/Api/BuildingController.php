<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Project;
use Illuminate\Http\Request;

class BuildingController extends Controller
{
    private function getRole(Request $request, Project $project): ?string
    {
        return $project->userRole($request->user()->id);
    }

    public function index(Request $request, Project $project)
    {
        $role = $this->getRole($request, $project);
        if (! $role) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $project->user_role = $role;

        return response()->json([
            'data'    => $project->buildings()->withCount('floors')->orderBy('created_at', 'desc')->get(),
            'project' => $project,
        ]);
    }

    public function store(Request $request, Project $project)
    {
        $role = $this->getRole($request, $project);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'floors'            => 'sometimes|integer|min:1',
            'area'              => 'required|numeric|min:0.01',
            'power_consumption' => 'sometimes|string|max:50',
        ]);

        $building = $project->buildings()->create($data);
        $project->increment('buildings_count');

        return response()->json(['data' => $building], 201);
    }

    public function update(Request $request, Project $project, Building $building)
    {
        $role = $this->getRole($request, $project);
        if (! in_array($role, ['admin', 'main']) || $building->project_id !== $project->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name'              => 'sometimes|string|max:255',
            'floors'            => 'sometimes|integer|min:1',
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

        $building->update($data);

        return response()->json(['data' => $building]);
    }

    public function destroy(Request $request, Project $project, Building $building)
    {
        $role = $this->getRole($request, $project);
        if (! in_array($role, ['admin', 'main']) || $building->project_id !== $project->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $building->delete();
        $project->decrement('buildings_count');

        return response()->json(['message' => 'Building deleted.']);
    }
}
