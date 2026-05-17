<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    private function getRole(Request $request, Floor $floor): ?string
    {
        return $floor->building->project->userRole($request->user()->id);
    }

    public function index(Request $request, Floor $floor)
    {
        $role = $this->getRole($request, $floor);
        if (! $role) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $floor->load('building.project');
        $floor->building->project->user_role = $role;

        return response()->json([
            'data'  => $floor->rooms()->orderBy('created_at', 'desc')->get(),
            'floor' => $floor,
        ]);
    }

    public function store(Request $request, Floor $floor)
    {
        $role = $this->getRole($request, $floor);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'name'              => 'required|string|max:255',
            'area'              => 'required|numeric|min:0.01',
            'power_consumption' => 'sometimes|string|max:50',
        ]);

        $room = $floor->rooms()->create($data);

        return response()->json(['data' => $room], 201);
    }

    public function update(Request $request, Floor $floor, Room $room)
    {
        $role = $this->getRole($request, $floor);
        if (! in_array($role, ['admin', 'main']) || $room->floor_id !== $floor->id) {
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

        $room->update($data);

        return response()->json(['data' => $room]);
    }

    public function destroy(Request $request, Floor $floor, Room $room)
    {
        $role = $this->getRole($request, $floor);
        if (! in_array($role, ['admin', 'main']) || $room->floor_id !== $floor->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $room->delete();

        return response()->json(['message' => 'Room deleted.']);
    }
}
