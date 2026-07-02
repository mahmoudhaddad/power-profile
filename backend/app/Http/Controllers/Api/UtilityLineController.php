<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUtilityLineRequest;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Project;
use App\Models\Room;
use App\Models\UtilityLine;
use Illuminate\Http\Request;

class UtilityLineController extends Controller
{
    /** Resolve parent model and return [$parent, $project]. */
    private function resolveParent(string $type, int $id): array
    {
        switch ($type) {
            case 'project':
                $parent = Project::findOrFail($id);
                return [$parent, $parent];
            case 'building':
                $parent = Building::findOrFail($id);
                return [$parent, $parent->project];
            case 'floor':
                $parent = Floor::findOrFail($id);
                return [$parent, $parent->building->project];
            case 'room':
                $parent = Room::findOrFail($id);
                return [$parent, $parent->floor->building->project];
            default:
                abort(404);
        }
    }

    public function index(Request $request, string $type, int $id)
    {
        [$parent, $project] = $this->resolveParent($type, $id);

        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $lines = $parent->utilityLines()->orderBy('created_at', 'asc')->get();
        $total = $lines->sum('power');

        return response()->json(['data' => $lines, 'total_power' => round($total, 2)]);
    }

    public function store(Request $request, string $type, int $id)
    {
        $request->validate([
            'name'                => 'required|string|max:255',
            'power'               => 'required|numeric|min:0',
            'phases'              => 'required|in:1phase,3phase',
            'tariff_per_kwh'      => 'sometimes|nullable|numeric|min:0',
            'peak_tariff_per_kwh' => 'sometimes|nullable|numeric|min:0',
            'peak_hours_start'    => 'sometimes|nullable|integer|min:0|max:23',
            'peak_hours_end'      => 'sometimes|nullable|integer|min:0|max:23',
        ]);

        [$parent, $project] = $this->resolveParent($type, $id);

        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $line = $parent->utilityLines()->create([
            'name'                => $request->name,
            'power'               => $request->power,
            'phases'              => $request->phases,
            'tariff_per_kwh'      => $request->input('tariff_per_kwh'),
            'peak_tariff_per_kwh' => $request->input('peak_tariff_per_kwh'),
            'peak_hours_start'    => $request->input('peak_hours_start'),
            'peak_hours_end'      => $request->input('peak_hours_end'),
        ]);

        return response()->json(['data' => $line], 201);
    }

    public function update(UpdateUtilityLineRequest $request, UtilityLine $line)
    {
        $project = $this->lineProject($line);
        if (! $project || ! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $line->fill($request->only('name', 'power', 'phases', 'tariff_per_kwh', 'peak_tariff_per_kwh', 'peak_hours_start', 'peak_hours_end'));
        $line->save();

        return response()->json(['data' => $line]);
    }

    public function destroy(Request $request, UtilityLine $line)
    {
        $project = $this->lineProject($line);
        if (! $project || ! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $line->delete();

        return response()->json(['message' => 'Line deleted.']);
    }

    private function lineProject(UtilityLine $line): ?Project
    {
        $parent = $line->lineable;
        if (! $parent) return null;

        return match (true) {
            $parent instanceof Project  => $parent,
            $parent instanceof Building => $parent->project,
            $parent instanceof Floor    => $parent->building->project,
            $parent instanceof Room     => $parent->floor->building->project,
            default => null,
        };
    }
}
