<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBuildingRequest;
use App\Http\Requests\UpdateBuildingRequest;
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

    public function store(StoreBuildingRequest $request, Project $project)
    {
        $role = $this->getRole($request, $project);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $building = $project->buildings()->create($request->validated());
        $project->increment('buildings_count');

        return response()->json(['data' => $building], 201);
    }

    public function update(UpdateBuildingRequest $request, Project $project, Building $building)
    {
        $role = $this->getRole($request, $project);
        if (! in_array($role, ['admin', 'main']) || $building->project_id !== $project->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $building->update($request->validated());

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
