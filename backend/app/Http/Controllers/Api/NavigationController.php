<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NavigationController extends Controller
{
    public function project(Request $request, $projectName)
    {
        $project = $request->user()->projects()
            ->where('name', urldecode($projectName))
            ->firstOrFail();

        return response()->json([
            'project'   => $project,
            'buildings' => $project->buildings()->withCount('floors')->orderBy('created_at', 'desc')->get(),
        ]);
    }

    public function building(Request $request, $projectName, $buildingName)
    {
        $project = $request->user()->projects()
            ->where('name', urldecode($projectName))
            ->firstOrFail();

        $building = $project->buildings()
            ->where('name', urldecode($buildingName))
            ->firstOrFail();

        return response()->json([
            'project'  => $project,
            'building' => $building,
            'floors'   => $building->floors()->withCount('rooms')->orderBy('created_at', 'desc')->get(),
        ]);
    }

    public function floor(Request $request, $projectName, $buildingName, $floorName)
    {
        $project = $request->user()->projects()
            ->where('name', urldecode($projectName))
            ->firstOrFail();

        $building = $project->buildings()
            ->where('name', urldecode($buildingName))
            ->firstOrFail();

        $floor = $building->floors()
            ->where('name', urldecode($floorName))
            ->firstOrFail();

        return response()->json([
            'project'  => $project,
            'building' => $building,
            'floor'    => $floor,
            'rooms'    => $floor->rooms()->orderBy('created_at', 'desc')->get(),
        ]);
    }

    public function room(Request $request, $projectName, $buildingName, $floorName, $roomName)
    {
        $project = $request->user()->projects()
            ->where('name', urldecode($projectName))
            ->firstOrFail();

        $building = $project->buildings()
            ->where('name', urldecode($buildingName))
            ->firstOrFail();

        $floor = $building->floors()
            ->where('name', urldecode($floorName))
            ->firstOrFail();

        $room = $floor->rooms()
            ->where('name', urldecode($roomName))
            ->firstOrFail();

        return response()->json([
            'project'    => $project,
            'building'   => $building,
            'floor'      => $floor,
            'room'       => $room,
            'components' => $room->components()->with('componentType')->orderBy('created_at', 'desc')->get(),
        ]);
    }
}
