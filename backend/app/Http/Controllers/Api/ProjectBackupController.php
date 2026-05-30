<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\ComponentType;
use App\Models\Floor;
use App\Models\Project;
use App\Models\Room;
use App\Models\ServerBackup;
use App\Services\BackupExporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectBackupController extends Controller
{
    // ─────────────────────────────────────────
    //  PROJECT EXPORT / IMPORT
    // ─────────────────────────────────────────

    public function backup(Request $request, Project $project)
    {
        $role = $project->userRole($request->user()->id);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'version'     => '1.0',
            'exported_at' => now()->toISOString(),
            'project'     => $this->exportProject($project),
        ]);
    }

    public function restore(Request $request)
    {
        set_time_limit(300);
        $request->validate([
            'data'      => 'required|array',
            'overwrite' => 'sometimes|boolean',
        ]);

        $raw         = $request->input('data');
        $projectData = isset($raw['project']) ? $raw['project'] : $raw;

        if (empty($projectData['name'])) {
            return response()->json(['message' => 'Invalid backup file: missing project name.'], 422);
        }

        $userId = $request->user()->id;
        $name   = $projectData['name'];

        // Block overwrite if the user only has read-only access to a project with this name
        $readonlyConflict = Project::whereHas('projectUsers', function ($q) use ($userId) {
            $q->where('user_id', $userId)->where('role', 'normal');
        })->where('name', $name)->exists();

        if ($readonlyConflict) {
            return response()->json([
                'message' => "You have read-only access to a project named \"{$name}\" and cannot overwrite it.",
            ], 403);
        }

        $existing = Project::where('user_id', $userId)->where('name', $name)->first();
        if ($existing && ! $request->boolean('overwrite')) {
            return response()->json([
                'conflict' => true,
                'message'  => "A project named \"{$name}\" already exists. Overwrite it?",
            ], 409);
        }

        DB::beginTransaction();
        try {
            if ($existing) {
                $existing->delete();
            }

            $project = $this->importProject($userId, $projectData);

            DB::commit();

            $project->user_role  = 'admin';
            $project->total_power = 0;

            return response()->json(['data' => $project], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Restore failed: ' . $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────
    //  SAVE TO SERVER
    // ─────────────────────────────────────────

    public function saveProjectToServer(Request $request, Project $project)
    {
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = ['version' => '1.0', 'exported_at' => now()->toISOString(), 'project' => $this->exportProject($project)];

        ServerBackup::create([
            'project_id'  => $project->id,
            'entity_type' => 'project',
            'entity_id'   => $project->id,
            'entity_name' => $project->name,
            'created_by'  => $request->user()->id,
            'data'        => json_encode($data),
        ]);

        return response()->json(['message' => 'Backup saved to server.'], 201);
    }

    public function saveBuildingToServer(Request $request, Building $building)
    {
        $project = $building->project;
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = ['version' => '1.0', 'exported_at' => now()->toISOString(), 'building' => $this->exportBuilding($building)];

        ServerBackup::create([
            'project_id'  => $project->id,
            'entity_type' => 'building',
            'entity_id'   => $building->id,
            'entity_name' => $building->name,
            'created_by'  => $request->user()->id,
            'data'        => json_encode($data),
        ]);

        return response()->json(['message' => 'Backup saved to server.'], 201);
    }

    public function saveFloorToServer(Request $request, Floor $floor)
    {
        $project = $floor->building->project;
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = ['version' => '1.0', 'exported_at' => now()->toISOString(), 'floor' => $this->exportFloor($floor)];

        ServerBackup::create([
            'project_id'  => $project->id,
            'entity_type' => 'floor',
            'entity_id'   => $floor->id,
            'entity_name' => $floor->name,
            'created_by'  => $request->user()->id,
            'data'        => json_encode($data),
        ]);

        return response()->json(['message' => 'Backup saved to server.'], 201);
    }

    public function saveRoomToServer(Request $request, Room $room)
    {
        $project = $room->floor->building->project;
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = ['version' => '1.0', 'exported_at' => now()->toISOString(), 'room' => $this->exportRoom($room)];

        ServerBackup::create([
            'project_id'  => $project->id,
            'entity_type' => 'room',
            'entity_id'   => $room->id,
            'entity_name' => $room->name,
            'created_by'  => $request->user()->id,
            'data'        => json_encode($data),
        ]);

        return response()->json(['message' => 'Backup saved to server.'], 201);
    }

    // ─────────────────────────────────────────
    //  BUILDING EXPORT / IMPORT
    // ─────────────────────────────────────────

    public function backupBuilding(Request $request, Building $building)
    {
        $project = $building->project;
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'version'     => '1.0',
            'exported_at' => now()->toISOString(),
            'building'    => $this->exportBuilding($building),
        ]);
    }

    public function restoreBuilding(Request $request, Project $project)
    {
        set_time_limit(300);
        $role = $project->userRole($request->user()->id);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'data'      => 'required|array',
            'overwrite' => 'sometimes|boolean',
        ]);

        $raw          = $request->input('data');
        $buildingData = isset($raw['building']) ? $raw['building'] : $raw;

        if (empty($buildingData['name'])) {
            return response()->json(['message' => 'Invalid backup: missing building name.'], 422);
        }

        $name     = $buildingData['name'];
        $existing = $project->buildings()->where('name', $name)->first();

        if ($existing && ! $request->boolean('overwrite')) {
            return response()->json([
                'conflict' => true,
                'message'  => "A building named \"{$name}\" already exists. Overwrite it?",
            ], 409);
        }

        DB::beginTransaction();
        try {
            if ($existing) $existing->delete();
            $building = $this->importBuilding($project, $buildingData);
            DB::commit();
            return response()->json(['data' => $building], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Restore failed: ' . $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────
    //  FLOOR EXPORT / IMPORT
    // ─────────────────────────────────────────

    public function backupFloor(Request $request, Floor $floor)
    {
        $project = $floor->building->project;
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'version'     => '1.0',
            'exported_at' => now()->toISOString(),
            'floor'       => $this->exportFloor($floor),
        ]);
    }

    public function restoreFloor(Request $request, Building $building)
    {
        set_time_limit(300);
        $project = $building->project;
        $role    = $project->userRole($request->user()->id);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'data'      => 'required|array',
            'overwrite' => 'sometimes|boolean',
        ]);

        $raw       = $request->input('data');
        $floorData = isset($raw['floor']) ? $raw['floor'] : $raw;

        if (empty($floorData['name'])) {
            return response()->json(['message' => 'Invalid backup: missing floor name.'], 422);
        }

        $name     = $floorData['name'];
        $existing = $building->floors()->where('name', $name)->first();

        if ($existing && ! $request->boolean('overwrite')) {
            return response()->json([
                'conflict' => true,
                'message'  => "A floor named \"{$name}\" already exists. Overwrite it?",
            ], 409);
        }

        DB::beginTransaction();
        try {
            if ($existing) $existing->delete();
            $floor = $this->importFloor($building, $floorData);
            DB::commit();
            return response()->json(['data' => $floor], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Restore failed: ' . $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────
    //  ROOM EXPORT / IMPORT
    // ─────────────────────────────────────────

    public function backupRoom(Request $request, Room $room)
    {
        $project = $room->floor->building->project;
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json([
            'version'     => '1.0',
            'exported_at' => now()->toISOString(),
            'room'        => $this->exportRoom($room),
        ]);
    }

    public function restoreRoom(Request $request, Floor $floor)
    {
        set_time_limit(300);
        $project = $floor->building->project;
        $role    = $project->userRole($request->user()->id);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'data'      => 'required|array',
            'overwrite' => 'sometimes|boolean',
        ]);

        $raw      = $request->input('data');
        $roomData = isset($raw['room']) ? $raw['room'] : $raw;

        if (empty($roomData['name'])) {
            return response()->json(['message' => 'Invalid backup: missing room name.'], 422);
        }

        $name     = $roomData['name'];
        $existing = $floor->rooms()->where('name', $name)->first();

        if ($existing && ! $request->boolean('overwrite')) {
            return response()->json([
                'conflict' => true,
                'message'  => "A room named \"{$name}\" already exists. Overwrite it?",
            ], 409);
        }

        DB::beginTransaction();
        try {
            if ($existing) $existing->delete();
            $room = $this->importRoom($floor, $roomData);
            DB::commit();
            return response()->json(['data' => $room], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Restore failed: ' . $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────
    //  DUPLICATE
    // ─────────────────────────────────────────

    public function duplicateBuilding(Request $request, Project $project, Building $building)
    {
        $role = $project->userRole($request->user()->id);
        if (! in_array($role, ['admin', 'main']) || $building->project_id !== $project->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        DB::beginTransaction();
        try {
            $data         = $this->exportBuilding($building);
            $data['name'] = $data['name'] . ' (Copy)';
            $copy         = $this->importBuilding($project, $data);
            DB::commit();
            return response()->json(['data' => $copy], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Duplicate failed: ' . $e->getMessage()], 500);
        }
    }

    public function duplicateFloor(Request $request, Building $building, Floor $floor)
    {
        $project = $building->project;
        $role    = $project->userRole($request->user()->id);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        // Floor must belong to the same project (not necessarily the same building)
        if ($floor->building->project_id !== $project->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        DB::beginTransaction();
        try {
            $data         = $this->exportFloor($floor);
            $data['name'] = $data['name'] . ' (Copy)';
            $copy         = $this->importFloor($building, $data);
            DB::commit();
            return response()->json(['data' => $copy], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Duplicate failed: ' . $e->getMessage()], 500);
        }
    }

    public function duplicateRoom(Request $request, Floor $floor, Room $room)
    {
        $project = $floor->building->project;
        $role    = $project->userRole($request->user()->id);
        if (! in_array($role, ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        // Room must belong to the same project (not necessarily the same floor)
        if ($room->floor->building->project_id !== $project->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        DB::beginTransaction();
        try {
            $data         = $this->exportRoom($room);
            $data['name'] = $data['name'] . ' (Copy)';
            $copy         = $this->importRoom($floor, $data);
            DB::commit();
            return response()->json(['data' => $copy], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Duplicate failed: ' . $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────
    //  EXPORT HELPERS  (delegated to service)
    // ─────────────────────────────────────────

    private function exporter(): BackupExporter
    {
        return app(BackupExporter::class);
    }

    private function exportProject(Project $project): array   { return $this->exporter()->exportProject($project); }
    private function exportBuilding($building): array         { return $this->exporter()->exportBuilding($building); }
    private function exportFloor($floor): array               { return $this->exporter()->exportFloor($floor); }
    private function exportRoom($room): array                 { return $this->exporter()->exportRoom($room); }
    private function exportComponents($components): array     { return []; } // unused — kept for safety

    // ─────────────────────────────────────────
    //  IMPORT HELPERS
    // ─────────────────────────────────────────

    private function importProject(int $userId, array $data): Project
    {
        $project = Project::create([
            'user_id'         => $userId,
            'name'            => $data['name'],
            'building_type'   => $data['building_type'] ?? null,
            'solar_power'     => $data['solar_power'] ?? null,
            'generator_power' => $data['generator_power'] ?? null,
            'buildings_count' => count($data['buildings'] ?? []),
        ]);

        $this->importLines($project, $data);
        $this->importComponents($project, $data['components'] ?? []);
        // Solar systems must be imported before batteries (batteries reference them by name)
        $solarNameToId = $this->importSolarSystems($project, $data['solar_systems'] ?? []);
        $this->importBatteries($project, $data['batteries'] ?? [], $solarNameToId);

        foreach ($data['buildings'] ?? [] as $buildingData) {
            $this->importBuilding($project, $buildingData);
        }

        return $project;
    }

    private function importSolarSystems(\App\Models\Project $project, array $systems): array
    {
        if (empty($systems)) return [];

        $now   = now()->toDateTimeString();
        $nameToId = [];
        foreach ($systems as $s) {
            $id = DB::table('solar_systems')->insertGetId([
                'project_id'  => $project->id,
                'name'        => $s['name'],
                'capacity_kw' => $s['capacity_kw'],
                'is_active'   => $s['is_active'] ?? true,
                'notes'       => $s['notes'] ?? null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $nameToId[$s['name']] = $id;
        }
        return $nameToId;
    }

    private function importBatteries(\App\Models\Project $project, array $batteries, array $solarNameToId = []): void
    {
        if (empty($batteries)) return;

        $now  = now()->toDateTimeString();
        $rows = [];
        foreach ($batteries as $b) {
            $solarSystemId = null;
            if (!empty($b['solar_system_name']) && isset($solarNameToId[$b['solar_system_name']])) {
                $solarSystemId = $solarNameToId[$b['solar_system_name']];
            }
            $rows[] = [
                'project_id'            => $project->id,
                'name'                  => $b['name'],
                'chemistry'             => $b['chemistry'],
                'nominal_voltage_v'     => $b['nominal_voltage_v'],
                'capacity_ah_per_unit'  => $b['capacity_ah_per_unit'],
                'quantity'              => $b['quantity'],
                'series_count'          => $b['series_count'],
                'parallel_count'        => $b['parallel_count'],
                'installation_date'     => $b['installation_date'],
                'depth_of_discharge'    => $b['depth_of_discharge'],
                'round_trip_efficiency' => $b['round_trip_efficiency'],
                'c_rate_charge'         => $b['c_rate_charge'],
                'c_rate_discharge'      => $b['c_rate_discharge'],
                'rated_cycle_life'      => $b['rated_cycle_life'],
                'current_soc'           => $b['current_soc'] ?? 0.50,
                'is_active'             => $b['is_active'] ?? true,
                'notes'                 => $b['notes'] ?? null,
                'solar_system_id'       => $solarSystemId,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }
        DB::table('batteries')->insert($rows);
    }

    private function importBuilding(Project $project, array $data): Building
    {
        $building = $project->buildings()->create([
            'name' => $data['name'],
            'area' => $data['area'] ?? 0,
        ]);

        $this->importLines($building, $data);
        $this->importComponents($building, $data['components'] ?? []);

        foreach ($data['floors'] ?? [] as $floorData) {
            $this->importFloor($building, $floorData);
        }

        return $building;
    }

    private function importFloor($building, array $data): Floor
    {
        $floor = $building->floors()->create([
            'name' => $data['name'],
            'area' => $data['area'] ?? 0,
        ]);

        $this->importLines($floor, $data);
        $this->importComponents($floor, $data['components'] ?? []);

        foreach ($data['rooms'] ?? [] as $roomData) {
            $this->importRoom($floor, $roomData);
        }

        return $floor;
    }

    private function importRoom($floor, array $data): Room
    {
        $room = $floor->rooms()->create([
            'name' => $data['name'],
            'area' => $data['area'] ?? 0,
        ]);

        $this->importLines($room, $data);

        $this->importComponents($room, $data['components'] ?? []);

        return $room;
    }

    private function importComponents($parent, array $components): void
    {
        if (empty($components)) return;

        $now = now()->toDateTimeString();

        // Resolve all component types in one query instead of N firstOrCreate calls
        $names   = array_unique(array_column($components, 'component_name'));
        $ctMap   = ComponentType::whereIn('name', $names)->pluck('id', 'name');
        $missing = array_diff($names, $ctMap->keys()->all());
        if ($missing) {
            ComponentType::insert(array_map(fn($n) => [
                'name' => $n, 'is_preset' => false,
                'created_at' => $now, 'updated_at' => $now,
            ], array_values($missing)));
            $ctMap = ComponentType::whereIn('name', $names)->pluck('id', 'name');
        }

        [$table, $parentCol] = match(true) {
            $parent instanceof \App\Models\Project  => ['project_components',  'project_id'],
            $parent instanceof \App\Models\Building => ['building_components', 'building_id'],
            $parent instanceof \App\Models\Floor    => ['floor_components',    'floor_id'],
            default                                 => ['room_components',     'room_id'],
        };

        $rows = [];
        foreach ($components as $c) {
            $rows[] = [
                $parentCol             => $parent->id,
                'component_type_id'    => $ctMap[$c['component_name']],
                'power'                => $c['power'],
                'phases'               => $c['phases']               ?? '1phase',
                'power_factor'         => $c['power_factor']         ?? 1.00,
                'quantity'             => $c['quantity']              ?? 1,
                'priority'             => $c['priority']              ?? 'normal',
                'group_name'           => $c['group_name']            ?? null,
                'needs_socket'         => $c['needs_socket']          ?? false,
                'usage_season'         => $c['usage_season']          ?? 'all',
                'usage_day_type'       => $c['usage_day_type']        ?? 'all',
                'usage_time_intervals' => isset($c['usage_time_intervals'])
                    ? json_encode($c['usage_time_intervals']) : null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }
        DB::table($table)->insert($rows);
    }

    private function importLines($parent, array $data): void
    {
        $now      = now()->toDateTimeString();
        $morphType = get_class($parent);
        $id        = $parent->id;

        $utilityRows = [];
        foreach ($data['utility_lines'] ?? [] as $line) {
            $utilityRows[] = [
                'lineable_type' => $morphType, 'lineable_id' => $id,
                'name' => $line['name'], 'power' => $line['power'],
                'phases' => $line['phases'] ?? '1phase',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if ($utilityRows) DB::table('utility_lines')->insert($utilityRows);

        $generatorRows = [];
        foreach ($data['generator_lines'] ?? [] as $line) {
            $generatorRows[] = [
                'generable_type' => $morphType, 'generable_id' => $id,
                'name' => $line['name'], 'power' => $line['power'],
                'phases' => $line['phases'] ?? '1phase',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if ($generatorRows) DB::table('generator_lines')->insert($generatorRows);

        $socketRows = [];
        foreach ($data['sockets'] ?? [] as $s) {
            $socketRows[] = [
                'socketable_type' => $morphType, 'socketable_id' => $id,
                'phase_type' => $s['phase_type'] ?? '1phase',
                'power' => $s['power'], 'quantity' => $s['quantity'] ?? 1,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        if ($socketRows) DB::table('sockets')->insert($socketRows);
    }
}
