<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGeneratorLineRequest;
use App\Models\Building;
use App\Models\Floor;
use App\Models\GeneratorLine;
use App\Models\Project;
use App\Models\Room;
use Illuminate\Http\Request;

class GeneratorLineController extends Controller
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

        $lines = $parent->generatorLines()->orderBy('created_at', 'asc')->get();
        $total = $lines->sum('power');

        return response()->json(['data' => $lines, 'total_power' => round($total, 2)]);
    }

    public function store(Request $request, string $type, int $id)
    {
        $request->validate([
            'name'   => 'required|string|max:255',
            'power'  => 'required|numeric|min:0',
            'phases' => 'required|in:1phase,3phase',
        ]);

        [$parent, $project] = $this->resolveParent($type, $id);

        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $line = $parent->generatorLines()->create([
            'name'   => $request->name,
            'power'  => $request->power,
            'phases' => $request->phases,
        ]);

        return response()->json(['data' => $line], 201);
    }

    public function update(UpdateGeneratorLineRequest $request, GeneratorLine $line)
    {
        $project = $this->lineProject($line);
        if (! $project || ! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $line->fill($request->only('name', 'power', 'phases'));
        $line->save();

        return response()->json(['data' => $line]);
    }

    public function destroy(Request $request, GeneratorLine $line)
    {
        $project = $this->lineProject($line);
        if (! $project || ! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $line->delete();

        return response()->json(['message' => 'Generator deleted.']);
    }

    private function lineProject(GeneratorLine $line): ?Project
    {
        $parent = $line->generable;
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
