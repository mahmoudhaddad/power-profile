<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\BuildingComponent;
use App\Models\FloorComponent;
use App\Models\Project;
use App\Models\RoomComponent;
use App\Services\SocketDemandService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(private SocketDemandService $socketService) {}

    public function index(Request $request)
    {
        $userId = $request->user()->id;

        // Own projects (user is admin)
        $ownProjects = Project::where('user_id', $userId)
            ->orderBy('updated_at', 'desc')
            ->get()
            ->each(fn($p) => $p->user_role = 'admin');

        // Shared projects (user is a member)
        $sharedProjects = Project::whereHas('projectUsers', fn($q) => $q->where('user_id', $userId))
            ->with(['projectUsers' => fn($q) => $q->where('user_id', $userId)])
            ->orderBy('updated_at', 'desc')
            ->get()
            ->each(fn($p) => $p->user_role = $p->projectUsers->first()?->role ?? 'normal');

        $projects = $ownProjects->merge($sharedProjects)->sortByDesc('updated_at')->values();

        foreach ($projects as $project) {
            $own      = $this->optimizedVA($project->components()->select('project_id', 'power', 'power_factor', 'quantity', 'group_name')->get(), 'project_id');
            $building = $this->optimizedVA(BuildingComponent::whereHas('building', fn($q) => $q->where('project_id', $project->id))->select('building_id', 'power', 'power_factor', 'quantity', 'group_name')->get(), 'building_id');
            $floor    = $this->optimizedVA(FloorComponent::whereHas('floor.building', fn($q) => $q->where('project_id', $project->id))->select('floor_id', 'power', 'power_factor', 'quantity', 'group_name')->get(), 'floor_id');
            $room     = $this->optimizedVA(RoomComponent::whereHas('room.floor.building', fn($q) => $q->where('project_id', $project->id))->select('room_id', 'power', 'power_factor', 'quantity', 'group_name')->get(), 'room_id');
            $sd       = $this->socketService->projectResult($project);

            $project->total_power = round($own + $building + $floor + $room + $sd['demand_va'], 2);
        }

        return response()->json(['data' => $projects]);
    }

    public function store(StoreProjectRequest $request)
    {
        $project = $request->user()->projects()->create($request->validated());
        $project->user_role = 'admin';

        return response()->json(['data' => $project], 201);
    }

    public function show(Request $request, Project $project)
    {
        $role = $project->userRole($request->user()->id);
        if (! $role) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $project->user_role = $role;
        return response()->json(['data' => $project]);
    }

    public function update(UpdateProjectRequest $request, Project $project)
    {
        $role = $project->userRole($request->user()->id);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $project->update($request->validated());
        $project->user_role = $role;

        return response()->json(['data' => $project]);
    }

    public function destroy(Request $request, Project $project)
    {
        if ($project->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden. Only the project admin can delete this project.'], 403);
        }

        $project->delete();

        return response()->json(['message' => 'Project deleted.']);
    }

    public function allFloors(Request $request, Project $project)
    {
        $role = $project->userRole($request->user()->id);
        if (! $role) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $floors = \App\Models\Floor::whereHas('building', fn($q) => $q->where('project_id', $project->id))
            ->select('id', 'name', 'area')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $floors]);
    }

    public function allRooms(Request $request, Project $project)
    {
        $role = $project->userRole($request->user()->id);
        if (! $role) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $rooms = \App\Models\Room::whereHas('floor.building', fn($q) => $q->where('project_id', $project->id))
            ->select('id', 'name', 'area')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rooms]);
    }

    private function optimizedVA($components, string $entityKey): float
    {
        $ungrouped = 0.0;
        $groups    = [];
        foreach ($components as $c) {
            $pf = max((float) ($c->power_factor ?? 1), 0.01);
            $va = (float) $c->power * (int) $c->quantity / $pf;
            if (!$c->group_name) {
                $ungrouped += $va;
            } else {
                $key = $c->{$entityKey} . '|' . $c->group_name;
                if (!isset($groups[$key]) || $va > $groups[$key]) {
                    $groups[$key] = $va;
                }
            }
        }
        return $ungrouped + array_sum($groups);
    }
}
