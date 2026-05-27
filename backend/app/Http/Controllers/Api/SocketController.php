<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSocketRequest;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Project;
use App\Models\Room;
use App\Models\Socket;
use Illuminate\Http\Request;

class SocketController extends Controller
{
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

        return response()->json(['data' => $parent->sockets()->orderBy('created_at', 'asc')->get()]);
    }

    public function store(Request $request, string $type, int $id)
    {
        $request->validate([
            'phase_type' => 'required|in:1phase,3phase',
            'power'      => 'required|numeric|min:0.01',
            'quantity'   => 'required|integer|min:1',
        ]);

        [$parent, $project] = $this->resolveParent($type, $id);

        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $socket = $parent->sockets()->create($request->only('phase_type', 'power', 'quantity'));

        return response()->json(['data' => $socket], 201);
    }

    public function update(UpdateSocketRequest $request, Socket $socket)
    {
        $project = $this->socketProject($socket);
        if (! $project || ! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $socket->fill($request->only('phase_type', 'power', 'quantity'))->save();

        return response()->json(['data' => $socket]);
    }

    public function destroy(Request $request, Socket $socket)
    {
        $project = $this->socketProject($socket);
        if (! $project || ! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $socket->delete();

        return response()->json(['message' => 'Socket deleted.']);
    }

    private function socketProject(Socket $socket): ?Project
    {
        $parent = $socket->socketable;
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
