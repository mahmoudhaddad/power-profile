<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
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

    public function store(StoreRoomRequest $request, Floor $floor)
    {
        $role = $this->getRole($request, $floor);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $room = $floor->rooms()->create($request->validated());

        return response()->json(['data' => $room], 201);
    }

    public function update(UpdateRoomRequest $request, Floor $floor, Room $room)
    {
        $role = $this->getRole($request, $floor);
        if (! in_array($role, ['admin', 'main']) || $room->floor_id !== $floor->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $room->update($request->validated());

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
