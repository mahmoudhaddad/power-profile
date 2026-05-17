<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComponentType;
use App\Models\Project;
use App\Models\ProjectComponent;
use Illuminate\Http\Request;

class ProjectComponentController extends Controller
{
    public function index(Request $request, Project $project)
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'data' => $project->components()->with('componentType')->orderBy('created_at', 'desc')->get(),
        ]);
    }

    public function store(Request $request, Project $project)
    {
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'component_name'  => 'required|string|max:255',
            'power'           => 'required|numeric|min:0.01',
            'phases'          => 'sometimes|in:1phase,3phase',
            'power_factor'    => 'sometimes|numeric|min:0.01|max:1',
            'quantity'        => 'required|integer|min:1',
            'priority'        => 'required|in:critical,essential,non_critical,normal',
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

        $needsSocket = $request->boolean('needs_socket', false);
        $season      = $request->input('usage_season', 'all');
        $dayType     = $request->input('usage_day_type', 'all');
        $timeIntervals = $request->input('usage_time_intervals', [['start' => '08:00', 'end' => '18:00']]);

        $this->saveDefaults($componentType, $request->power, $request->input('phases', '1phase'), $request->input('power_factor', 1.00), $needsSocket, $season, $dayType, $timeIntervals);

        $component = $project->components()->create([
            'component_type_id' => $componentType->id,
            'power'             => $request->power,
            'phases'            => $request->input('phases', '1phase'),
            'power_factor'      => $request->input('power_factor', 1.00),
            'quantity'          => $request->quantity,
            'group_name'        => $request->input('group_name'),
            'priority'          => $request->priority,
            'needs_socket'      => $needsSocket,
            'usage_season'      => $season,
            'usage_day_type'    => $dayType,
            'usage_time_intervals' => $timeIntervals,
        ]);

        return response()->json(['data' => $component->load('componentType')], 201);
    }

    public function update(Request $request, Project $project, ProjectComponent $component)
    {
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])
            || $component->project_id !== $project->id) {
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
        $component->group_name        = $request->input('group_name', $component->group_name);
        $component->needs_socket      = $needsSocket;
        $component->usage_season      = $season;
        $component->usage_day_type    = $dayType;
        $component->usage_time_intervals = $timeIntervals;
        $component->save();

        return response()->json(['data' => $component->load('componentType')]);
    }

    public function destroy(Request $request, Project $project, ProjectComponent $component)
    {
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])
            || $component->project_id !== $project->id) {
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
