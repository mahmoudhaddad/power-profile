<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFloorRequest;
use App\Http\Requests\UpdateFloorRequest;
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

    public function store(StoreFloorRequest $request, Building $building)
    {
        $role = $this->getRole($request, $building);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $floor = $building->floors()->create($request->validated());

        return response()->json(['data' => $floor], 201);
    }

    public function update(UpdateFloorRequest $request, Building $building, Floor $floor)
    {
        $role = $this->getRole($request, $building);
        if (! in_array($role, ['admin', 'main']) || $floor->building_id !== $building->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $floor->update($request->validated());

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
