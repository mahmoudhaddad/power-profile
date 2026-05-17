<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComponentType;
use App\Models\Room;
use App\Models\RoomComponent;
use Illuminate\Http\Request;

class RoomComponentController extends Controller
{
    private function getRole(Request $request, Room $room): ?string
    {
        return $room->floor->building->project->userRole($request->user()->id);
    }

    public function index(Request $request, Room $room)
    {
        $role = $this->getRole($request, $room);
        if (! $role) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $room->load('floor.building.project');
        $room->floor->building->project->user_role = $role;

        return response()->json([
            'data' => $room->components()->with('componentType')->orderBy('created_at', 'desc')->get(),
            'room' => $room,
        ]);
    }

    public function store(Request $request, Room $room)
    {
        if (! in_array($this->getRole($request, $room), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'component_name'  => 'required|string|max:255',
            'power'           => 'required|numeric|min:0.01',
            'phases'          => 'sometimes|in:1phase,3phase',
            'power_factor'    => 'sometimes|numeric|min:0.01|max:1',
            'quantity'        => 'sometimes|integer|min:1',
            'priority'        => 'sometimes|in:critical,essential,non_critical,normal',
            'needs_socket'    => 'sometimes|boolean',
            'usage_season'    => 'sometimes|in:summer,winter,all',
            'usage_day_type'  => 'sometimes|in:weekday,weekend,all',
            'usage_time_intervals'         => 'sometimes|array|min:1',
            'usage_time_intervals.*.start' => 'required_with:usage_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'usage_time_intervals.*.end'   => 'required_with:usage_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'group_name'                   => 'sometimes|nullable|string|max:255',
        ]);

        $componentType = ComponentType::firstOrCreate(
            ['name' => $request->component_name],
            ['is_preset' => false]
        );

        $needsSocket  = $request->boolean('needs_socket', false);
        $season       = $request->input('usage_season', 'all');
        $dayType      = $request->input('usage_day_type', 'all');
        $timeIntervals = $request->input('usage_time_intervals', [['start' => '08:00', 'end' => '18:00']]);

        $this->saveDefaults($componentType, $request->power, $request->input('phases', '1phase'), $request->input('power_factor', 1.00), $needsSocket, $season, $dayType, $timeIntervals);

        $component = $room->components()->create([
            'component_type_id'    => $componentType->id,
            'power'                => $request->power,
            'phases'               => $request->input('phases', '1phase'),
            'power_factor'         => $request->input('power_factor', 1.00),
            'quantity'             => $request->input('quantity', 1),
            'group_name'           => $request->input('group_name'),
            'priority'             => $request->input('priority', 'normal'),
            'needs_socket'         => $needsSocket,
            'usage_season'         => $season,
            'usage_day_type'       => $dayType,
            'usage_time_intervals' => $timeIntervals,
        ]);

        return response()->json(['data' => $component->load('componentType')], 201);
    }

    public function update(Request $request, Room $room, RoomComponent $component)
    {
        if (! in_array($this->getRole($request, $room), ['admin', 'main'])
            || $component->room_id !== $room->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'component_name'  => 'sometimes|string|max:255',
            'power'           => 'sometimes|numeric|min:0.01',
            'phases'          => 'sometimes|in:1phase,3phase',
            'power_factor'    => 'sometimes|numeric|min:0.01|max:1',
            'quantity'        => 'sometimes|integer|min:1',
            'priority'        => 'sometimes|in:critical,essential,non_critical,normal',
            'needs_socket'    => 'sometimes|boolean',
            'usage_season'    => 'sometimes|in:summer,winter,all',
            'usage_day_type'  => 'sometimes|in:weekday,weekend,all',
            'usage_time_intervals'         => 'sometimes|array|min:1',
            'usage_time_intervals.*.start' => 'required_with:usage_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'usage_time_intervals.*.end'   => 'required_with:usage_time_intervals|string|regex:/^\d{2}:\d{2}$/',
            'group_name'                   => 'sometimes|nullable|string|max:255',
        ]);

        if ($request->has('component_name')) {
            $componentType = ComponentType::firstOrCreate(
                ['name' => $request->component_name],
                ['is_preset' => false]
            );
            $component->component_type_id = $componentType->id;
        } else {
            $componentType = $component->componentType;
        }

        $needsSocket = $request->boolean('needs_socket', $component->needs_socket);
        $season      = $request->input('usage_season',      $component->usage_season);
        $dayType     = $request->input('usage_day_type',    $component->usage_day_type);
        $timeIntervals = $request->input('usage_time_intervals', $component->usage_time_intervals ?? [['start' => '08:00', 'end' => '18:00']]);

        $this->saveDefaults(
            $componentType,
            $request->input('power', $component->power),
            $request->input('phases', $component->phases),
            $request->input('power_factor', $component->power_factor),
            $needsSocket, $season, $dayType, $timeIntervals
        );

        $component->fill($request->only('power', 'phases', 'power_factor', 'quantity', 'priority'));
        $component->group_name           = $request->input('group_name', $component->group_name);
        $component->needs_socket         = $needsSocket;
        $component->usage_season         = $season;
        $component->usage_day_type       = $dayType;
        $component->usage_time_intervals = $timeIntervals;
        $component->save();

        return response()->json(['data' => $component->load('componentType')]);
    }

    public function destroy(Request $request, Room $room, RoomComponent $component)
    {
        if (! in_array($this->getRole($request, $room), ['admin', 'main'])
            || $component->room_id !== $room->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $component->delete();

        return response()->json(['message' => 'Component deleted.']);
    }

    private function saveDefaults(ComponentType $ct, $power, $phases, $pf, bool $needsSocket = false, string $season = 'all', string $dayType = 'all', array $timeIntervals = []): void
    {
        if (! $ct->is_preset) {
            $ct->update([
                'default_power'                => $power,
                'default_phases'               => $phases,
                'default_power_factor'         => $pf,
                'default_needs_socket'         => $needsSocket,
                'default_usage_season'         => $season,
                'default_usage_day_type'       => $dayType,
                'default_usage_time_intervals' => $timeIntervals ?: null,
            ]);
        }
    }
}
