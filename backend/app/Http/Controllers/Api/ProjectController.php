<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuildingComponent;
use App\Models\FloorComponent;
use App\Models\Project;
use App\Models\RoomComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
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
            $own = $project->components()->sum(DB::raw('power * power_factor * quantity'));

            $building = BuildingComponent::whereHas('building', fn($q) =>
                $q->where('project_id', $project->id)
            )->sum(DB::raw('power * power_factor * quantity'));

            $floor = FloorComponent::whereHas('floor.building', fn($q) =>
                $q->where('project_id', $project->id)
            )->sum(DB::raw('power * power_factor * quantity'));

            $room = RoomComponent::whereHas('room.floor.building', fn($q) =>
                $q->where('project_id', $project->id)
            )->sum(DB::raw('power * power_factor * quantity'));

            $project->total_power = round($own + $building + $floor + $room, 2);
        }

        return response()->json(['data' => $projects]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'            => 'required|string|max:255',
            'buildings_count' => 'sometimes|integer|min:0',
            'total_power'     => 'sometimes|string|max:50',
        ]);

        $project = $request->user()->projects()->create($data);
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

    public function update(Request $request, Project $project)
    {
        $role = $project->userRole($request->user()->id);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name'                  => 'sometimes|string|max:255',
            'building_type'         => 'sometimes|nullable|string|max:50',
            'current_step'          => 'sometimes|integer|min:1',
            'buildings_count'       => 'sometimes|integer|min:0',
            'total_power'           => 'sometimes|string|max:50',
            'solar_power'           => 'sometimes|nullable|numeric|min:0',
            'existing_solar_power'  => 'sometimes|nullable|numeric|min:0',
            'solar_source'          => 'sometimes|in:max,existing',
            'generator_power'       => 'sometimes|nullable|numeric|min:0',
            'location_lat'          => 'sometimes|nullable|numeric|between:-90,90',
            'location_lng'          => 'sometimes|nullable|numeric|between:-180,180',
            'location_name'         => 'sometimes|nullable|string|max:255',
            'auto_backup_interval'  => 'sometimes|in:never,daily,weekly,monthly',
            'work_days'             => 'sometimes|nullable|array',
            'work_days.*'           => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'work_time_intervals'                   => 'sometimes|nullable|array',
            'work_time_intervals.*.start'           => 'required_with:work_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'work_time_intervals.*.end'             => 'required_with:work_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'working_season_intervals'              => 'sometimes|nullable|array',
            'working_season_intervals.*.from'       => 'required_with:working_season_intervals|string|regex:/^\d{2}-\d{2}$/',
            'working_season_intervals.*.to'         => 'required_with:working_season_intervals|string|regex:/^\d{2}-\d{2}$/',
        ]);

        $project->update($data);
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
}
