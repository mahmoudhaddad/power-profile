<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SolarSystem;
use Illuminate\Http\Request;

class SolarSystemController extends Controller
{
    public function index(Request $request, Project $project)
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $systems = $project->solarSystems()->orderBy('created_at')->get();

        return response()->json([
            'data'       => $systems,
            'total_kw'   => round($systems->where('is_active', true)->sum('capacity_kw'), 2),
        ]);
    }

    public function store(Request $request, Project $project)
    {
        $role = $project->userRole($request->user()->id);
        if (! $role) return response()->json(['error' => 'Forbidden'], 403);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['error' => 'Read-only access'], 403);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'capacity_kw' => 'required|numeric|min:0.01|max:100000',
            'is_active'   => 'nullable|boolean',
            'notes'       => 'nullable|string|max:2000',
        ]);

        $system = $project->solarSystems()->create($validated);

        return response()->json(['data' => $system], 201);
    }

    public function update(Request $request, SolarSystem $solarSystem)
    {
        $role = $solarSystem->project->userRole($request->user()->id);
        if (! $role) return response()->json(['error' => 'Forbidden'], 403);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['error' => 'Read-only access'], 403);
        }

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'capacity_kw' => 'sometimes|numeric|min:0.01|max:100000',
            'is_active'   => 'sometimes|boolean',
            'notes'       => 'sometimes|nullable|string|max:2000',
        ]);

        $solarSystem->update($validated);

        return response()->json(['data' => $solarSystem->fresh()]);
    }

    public function destroy(Request $request, SolarSystem $solarSystem)
    {
        $role = $solarSystem->project->userRole($request->user()->id);
        if (! $role) return response()->json(['error' => 'Forbidden'], 403);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['error' => 'Read-only access'], 403);
        }

        // Detach any batteries linked to this system before deleting
        $solarSystem->batteries()->update(['solar_system_id' => null]);
        $solarSystem->delete();

        return response()->json(null, 204);
    }
}
