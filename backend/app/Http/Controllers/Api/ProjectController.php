<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\BuildingComponent;
use App\Models\Floor;
use App\Models\FloorComponent;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\Room;
use App\Models\RoomComponent;
use App\Models\Socket;
use App\Services\SolarIrradianceService;
use App\Services\SocketDemandService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        private SocketDemandService    $socketService,
        private SolarIrradianceService $solarSvc,
    ) {}

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
            $own      = $this->optimizedPower($project->components()->select('project_id', 'power', 'power_factor', 'quantity', 'group_name')->get(), 'project_id');
            $building = $this->optimizedPower(BuildingComponent::whereHas('building', fn($q) => $q->where('project_id', $project->id))->select('building_id', 'power', 'power_factor', 'quantity', 'group_name')->get(), 'building_id');
            $floor    = $this->optimizedPower(FloorComponent::whereHas('floor.building', fn($q) => $q->where('project_id', $project->id))->select('floor_id', 'power', 'power_factor', 'quantity', 'group_name')->get(), 'floor_id');
            $room     = $this->optimizedPower(RoomComponent::whereHas('room.floor.building', fn($q) => $q->where('project_id', $project->id))->select('room_id', 'power', 'power_factor', 'quantity', 'group_name')->get(), 'room_id');
            $sd       = $this->socketService->projectResult($project);

            $totalVa = $own['va'] + $building['va'] + $floor['va'] + $room['va'] + $sd['demand_va'];
            $totalW  = $own['w']  + $building['w']  + $floor['w']  + $room['w']  + $sd['demand_va'];

            $project->total_power = round($totalVa, 2);   // apparent power in VA (kVA when /1000)
            $project->total_kw    = round($totalW  / 1000, 3); // active power in kW
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

    /**
     * GET /api/projects/{project}/shiftable-components
     *
     * Returns all components with load_flexibility = 'shiftable' across every
     * hierarchy level (project / building / floor / room) for this project.
     * Used by the Load Schedule page Shiftable Loads tab.
     */
    public function shiftableComponents(Request $request, Project $project)
    {
        $role = $project->userRole($request->user()->id);
        if (! $role) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $result = collect();

        // Project-level components
        $project->components()
            ->where('load_flexibility', 'shiftable')
            ->with('componentType')
            ->get()
            ->each(fn($c) => $result->push($this->shiftableRow($c, 'Project', $project->name)));

        // Building / floor / room levels
        $buildings = $project->buildings()->with([
            'components' => fn($q) => $q->where('load_flexibility', 'shiftable')->with('componentType'),
            'floors.components' => fn($q) => $q->where('load_flexibility', 'shiftable')->with('componentType'),
            'floors.rooms.components' => fn($q) => $q->where('load_flexibility', 'shiftable')->with('componentType'),
        ])->get();

        foreach ($buildings as $building) {
            foreach ($building->components as $c) {
                $result->push($this->shiftableRow($c, 'Building', $building->name));
            }
            foreach ($building->getRelation('floors') as $floor) {
                foreach ($floor->components as $c) {
                    $result->push($this->shiftableRow($c, 'Floor', "{$building->name} › {$floor->name}"));
                }
                foreach ($floor->rooms as $room) {
                    foreach ($room->components as $c) {
                        $result->push($this->shiftableRow($c, 'Room', "{$building->name} › {$floor->name} › {$room->name}"));
                    }
                }
            }
        }

        return response()->json(['data' => $result->values()]);
    }

    private function shiftableRow($component, string $level, string $location): array
    {
        $ivs = $component->usage_time_intervals;
        if (is_string($ivs)) $ivs = json_decode($ivs, true);

        $modelTypeMap = [
            'Project'  => 'project',
            'Building' => 'building',
            'Floor'    => 'floor',
            'Room'     => 'room',
        ];

        return [
            'id'                  => $component->id,
            'model_type'          => $modelTypeMap[$level] ?? 'project',
            'name'                => $component->componentType->name ?? 'Unknown',
            'level'               => $level,
            'location'            => $location,
            'power'               => $component->power,
            'required_run_hours'  => $component->required_run_hours,
            'earliest_start_hour' => $component->earliest_start_hour,
            'latest_end_hour'     => $component->latest_end_hour,
            'min_continuous_run'  => $component->min_continuous_run,
            'max_interruptions'   => $component->max_interruptions,
            'usage_time_intervals'=> $ivs ?? [],
            'assigned'            => !empty($ivs),
        ];
    }

    // ── POST /api/projects/{project}/optimize-shiftable ───────────────────────

    public function optimizeShiftable(Request $request, Project $project)
    {
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'month'              => 'required|integer|min:1|max:12',
            'components'         => 'required|array|min:1',
            'components.*.id'    => 'required|integer',
            'components.*.model_type' => 'required|in:project,building,floor,room',
        ]);

        $month      = (int) $request->input('month');
        $costSignal = $this->buildCostSignal($project, $month);
        $updated    = [];
        $errors     = [];

        foreach ($request->input('components') as $item) {
            $comp = $this->resolveComponent($project, (int) $item['id'], $item['model_type']);
            if (! $comp) continue;

            // A.7 — Validate constraints before scheduling
            $runHours = (int) ($comp->required_run_hours ?? 0);
            $winStart = (int) ($comp->earliest_start_hour ?? 0);
            $winEnd   = (int) ($comp->latest_end_hour     ?? 24);
            $width    = $winEnd - $winStart;
            $name     = $comp->componentType->name ?? "Component #{$comp->id}";

            if ($runHours <= 0) {
                $errors[] = [
                    'error'        => 'invalid_constraints',
                    'component_id' => $comp->id,
                    'message'      => "'{$name}' has no required_run_hours set. Set it before optimizing.",
                ];
                continue;
            }

            if ($width <= 0 || $runHours > $width) {
                $errors[] = [
                    'error'        => 'invalid_constraints',
                    'component_id' => $comp->id,
                    'message'      => "'{$name}' requires {$runHours} run hour(s) but the allowed window "
                                    . "[{$winStart}:00 – {$winEnd}:00] is only {$width} hour(s) wide.",
                ];
                continue;
            }

            $intervals = $this->pickBestIntervals($comp, $costSignal);
            if (! $intervals) continue;

            // A.6 — Only save if there is genuine improvement OR the current interval
            // is the wrong width (wider or narrower than runHours).
            // A wide interval (e.g. 08:00–18:00 for a 4h load) makes buildHourlyW() count
            // the component as active for 10 hours instead of 4, inflating the load profile.
            // Always tighten such intervals; only skip if already exactly runHours wide.
            $currentIvs = $comp->usage_time_intervals;
            if (is_string($currentIvs)) $currentIvs = json_decode($currentIvs, true) ?? [];

            $currentScheduledHours = 0;
            $currentBestCost = PHP_FLOAT_MAX;
            foreach (($currentIvs ?: []) as $iv) {
                $ivS = (int) explode(':', $iv['start'] ?? '00:00')[0];
                $ivE = (int) explode(':', $iv['end']   ?? '23:59')[0];
                if ($ivE <= $ivS) continue;
                $currentScheduledHours += $ivE - $ivS;
                for ($s2 = $ivS; $s2 + $runHours <= $ivE && $s2 < 24; $s2++) {
                    $c2 = 0.0;
                    for ($h = $s2; $h < $s2 + $runHours; $h++) $c2 += $costSignal[$h] ?? 1.0;
                    if ($c2 < $currentBestCost) $currentBestCost = $c2;
                }
            }

            $newCost = 0.0;
            foreach ($intervals as $iv) {
                $s = (int) explode(':', $iv['start'])[0];
                $e = (int) explode(':', $iv['end'])[0];
                for ($h = $s; $h < $e && $h < 24; $h++) $newCost += $costSignal[$h] ?? 1.0;
            }

            // Skip only when current interval is already the exact right width AND
            // has an equally good or better placement (genuine no-improvement case).
            if ($currentScheduledHours === $runHours
                && $currentBestCost < PHP_FLOAT_MAX
                && $newCost >= $currentBestCost - 0.0001) {
                continue;
            }

            $savings = $currentBestCost < PHP_FLOAT_MAX ? round($currentBestCost - $newCost, 4) : 0.0;

            $comp->usage_time_intervals = $intervals;
            $comp->save();

            $updated[] = [
                'id'                   => $comp->id,
                'model_type'           => $item['model_type'],
                'usage_time_intervals' => $intervals,
                'savings'              => $savings,
                'savings_percent'      => ($currentBestCost > 0 && $currentBestCost < PHP_FLOAT_MAX)
                    ? round($savings / $currentBestCost * 100, 1)
                    : 0.0,
                'recommended_reason'   => null,
            ];
        }

        return response()->json([
            'data'            => $updated,
            'optimized_count' => count($updated),
            'errors'          => $errors,
        ]);
    }

    private function buildCostSignal(Project $project, int $month): array
    {
        // ── Monetary cost layer ───────────────────────────────────────────────
        $utilLine   = $project->utilityLines()->whereNotNull('tariff_per_kwh')->orderBy('id')->first();
        $tariff     = $utilLine ? (float) $utilLine->tariff_per_kwh : null;
        $peakTariff = ($utilLine && $utilLine->peak_tariff_per_kwh) ? (float) $utilLine->peak_tariff_per_kwh : null;
        $peakStart  = $utilLine ? (int) ($utilLine->peak_hours_start ?? 0) : 0;
        $peakEnd    = $utilLine ? (int) ($utilLine->peak_hours_end   ?? 0) : 0;

        $genLine = $project->generatorLines()
            ->whereNotNull('fuel_cost_per_liter')->whereNotNull('fuel_consumption_lph')
            ->where('fuel_cost_per_liter', '>', 0)->where('fuel_consumption_lph', '>', 0)
            ->orderBy('id')->first();
        $genCost = null;
        if ($genLine && $genLine->power > 0) {
            $genCost = round((float) $genLine->fuel_cost_per_liter * (float) $genLine->fuel_consumption_lph / ((float) $genLine->power / 1000.0), 4);
        }

        $baseMonetary = $tariff ?? $genCost;

        // ── Solar irradiance layer (always computed when location is set) ─────
        // Inverse irradiance: high sun = low cost, so the optimizer prefers
        // running loads during solar hours rather than before/after sunrise.
        $lat = $project->location_lat !== null ? (float) $project->location_lat : null;
        $lng = $project->location_lng !== null ? (float) $project->location_lng : null;

        $solarW = ($lat !== null)
            ? $this->solarSvc->getHourlyOutputWatts($lat, $lng, $month, 1.0, 0.80, 15)
            : array_fill(0, 24, 0.0);

        $maxSolar = max($solarW) ?: 1.0; // normalise to [0,1]

        $signal = [];
        for ($h = 0; $h < 24; $h++) {
            $inPeak      = $peakTariff && $peakEnd > $peakStart && $h >= $peakStart && $h < $peakEnd;
            $solarFrac   = (float) $solarW[$h] / $maxSolar;   // 0 = no sun, 1 = peak sun

            if ($baseMonetary !== null) {
                // Monetary costs exist: use tariff/generator as the base.
                // Discount during solar hours so loads shift toward solar generation.
                $base   = $inPeak ? $peakTariff : $tariff ?? $genCost;
                // Solar discount up to 90% of base cost during peak-sun hours
                $signal[$h] = round(max(0.0, (float) $base * (1.0 - 0.9 * $solarFrac)), 6);
            } else {
                // No monetary cost at all — use pure inverse irradiance so the
                // optimizer still prefers solar hours over dark hours.
                // cost = 1 - solarFrac  → 0 at peak sun, 1 at night
                $signal[$h] = round(1.0 - $solarFrac, 6);
            }
        }
        return $signal;
    }

    private function resolveComponent(Project $project, int $id, string $modelType): ?object
    {
        return match ($modelType) {
            'project'  => ProjectComponent::where('id', $id)->where('project_id', $project->id)->first(),
            'building' => BuildingComponent::where('id', $id)->whereHas('building', fn($q) => $q->where('project_id', $project->id))->first(),
            'floor'    => FloorComponent::where('id', $id)->whereHas('floor.building', fn($q) => $q->where('project_id', $project->id))->first(),
            'room'     => RoomComponent::where('id', $id)->whereHas('room.floor.building', fn($q) => $q->where('project_id', $project->id))->first(),
            default    => null,
        };
    }

    private function pickBestIntervals(object $comp, array $costSignal): ?array
    {
        $runHours  = (int) ($comp->required_run_hours ?? 0);
        $winStart  = (int) ($comp->earliest_start_hour ?? 0);
        $winEnd    = (int) ($comp->latest_end_hour     ?? 24);
        $maxSplits = (int) ($comp->max_interruptions   ?? 0);

        if ($runHours <= 0 || $winEnd <= $winStart || $runHours > ($winEnd - $winStart)) {
            return null;
        }

        $pad = fn(int $h) => str_pad($h, 2, '0', STR_PAD_LEFT) . ':00';

        if ($maxSplits === 0) {
            // No splits — find cheapest consecutive block of $runHours
            $bestCost  = PHP_FLOAT_MAX;
            $bestStart = $winStart;
            for ($s = $winStart; $s + $runHours <= $winEnd; $s++) {
                $cost = 0.0;
                for ($h = $s; $h < $s + $runHours; $h++) $cost += $costSignal[$h] ?? 1.0;
                if ($cost < $bestCost) { $bestCost = $cost; $bestStart = $s; }
            }
            $endH = min($bestStart + $runHours, 24);
            return [['start' => $pad($bestStart), 'end' => $endH === 24 ? '23:59' : $pad($endH)]];
        }

        // Allow splits — pick cheapest individual hours then group consecutive ones
        $hourCosts = [];
        for ($h = $winStart; $h < $winEnd; $h++) $hourCosts[$h] = $costSignal[$h] ?? 1.0;
        asort($hourCosts);
        $chosen = array_slice(array_keys($hourCosts), 0, $runHours);
        sort($chosen);

        $intervals = [];
        $gs = null; $prev = null;
        foreach ($chosen as $h) {
            if ($gs === null) { $gs = $h; }
            elseif ($h !== $prev + 1) {
                $intervals[] = ['start' => $pad($gs), 'end' => $pad($prev + 1)];
                $gs = $h;
            }
            $prev = $h;
        }
        if ($gs !== null) {
            $endH = min($prev + 1, 24);
            $intervals[] = ['start' => $pad($gs), 'end' => $endH === 24 ? '23:59' : $pad($endH)];
        }

        return $intervals ?: null;
    }

    private function optimizedPower($components, string $entityKey): array
    {
        $ungroupedVa = 0.0; $ungroupedW = 0.0;
        $groupsVa    = [];  $groupsW    = [];

        foreach ($components as $c) {
            $pf = max((float) ($c->power_factor ?? 1), 0.01);
            $w  = (float) $c->power * (int) $c->quantity;  // active power in W
            $va = $w / $pf;                                  // apparent power in VA
            if (!$c->group_name) {
                $ungroupedW  += $w;
                $ungroupedVa += $va;
            } else {
                $key = $c->{$entityKey} . '|' . $c->group_name;
                if (!isset($groupsVa[$key]) || $va > $groupsVa[$key]) {
                    $groupsVa[$key] = $va;
                    $groupsW[$key]  = $w;
                }
            }
        }
        return [
            'va' => $ungroupedVa + array_sum($groupsVa),
            'w'  => $ungroupedW  + array_sum($groupsW),
        ];
    }

    // ── GET /api/projects/{project}/defense-summary ───────────────────────────

    public function defenseSummary(Request $request, Project $project)
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $project->load([
            'components.componentType',
            'buildings.components.componentType',
            'buildings.floors.components.componentType',
            'buildings.floors.rooms.components.componentType',
            'solarSystems',
            'batteries',
            'generatorLines',
            'utilityLines',
        ]);

        // ── Hierarchy counts ──────────────────────────────────────────────────
        $buildingCount = $project->buildings->count();
        $floorCount    = $project->buildings->sum(fn($b) => $b->floors->count());
        $roomCount     = $project->buildings->sum(fn($b) => $b->floors->sum(fn($f) => $f->rooms->count()));

        // ── Merge all component collections ───────────────────────────────────
        $allComponents = $project->components
            ->concat($project->buildings->flatMap(fn($b) => $b->components))
            ->concat($project->buildings->flatMap(fn($b) => $b->floors->flatMap(fn($f) => $f->components)))
            ->concat($project->buildings->flatMap(fn($b) => $b->floors->flatMap(fn($f) => $f->rooms->flatMap(fn($r) => $r->components))));

        $componentCount   = $allComponents->count();
        $shiftableCount   = $allComponents->where('load_flexibility', 'shiftable')->count();
        $curtailableCount = $allComponents->where('load_flexibility', 'curtailable')->count();
        $motorCount       = $allComponents->filter(fn($c) => optional($c->componentType)->is_motor)->count();

        // ── Socket count across all levels ────────────────────────────────────
        $buildingIds = $project->buildings->pluck('id');
        $floorIds    = Floor::whereIn('building_id', $buildingIds)->pluck('id');
        $roomIds     = Room::whereIn('floor_id', $floorIds)->pluck('id');

        $socketCount = Socket::where(function ($q) use ($project, $buildingIds, $floorIds, $roomIds) {
            $q->where(fn($q2) => $q2->where('socketable_type', 'App\Models\Project')->where('socketable_id', $project->id))
              ->orWhere(fn($q2) => $q2->where('socketable_type', 'App\Models\Building')->whereIn('socketable_id', $buildingIds))
              ->orWhere(fn($q2) => $q2->where('socketable_type', 'App\Models\Floor')->whereIn('socketable_id', $floorIds))
              ->orWhere(fn($q2) => $q2->where('socketable_type', 'App\Models\Room')->whereIn('socketable_id', $roomIds));
        })->count();

        // ── Total demand (undiversified connected load) ───────────────────────
        $totalVa  = $allComponents->sum(fn($c) => (float) $c->power * max(1, (int) ($c->quantity ?? 1)));
        $totalW   = $allComponents->sum(fn($c) => (float) $c->power * max(1, (int) ($c->quantity ?? 1)) * (float) ($c->power_factor ?? 1));
        $totalVar = sqrt(max(0.0, $totalVa ** 2 - $totalW ** 2));
        $sysPf    = $totalVa > 0 ? round($totalW / $totalVa, 3) : 1.0;

        // ── Power sources ─────────────────────────────────────────────────────
        $activeSolar     = $project->solarSystems->where('is_active', true);
        $activeBatteries = $project->batteries->where('is_active', true);

        return response()->json([
            'component_count'        => $componentCount,
            'building_count'         => $buildingCount,
            'floor_count'            => $floorCount,
            'room_count'             => $roomCount,
            'socket_count'           => $socketCount,
            'shiftable_load_count'   => $shiftableCount,
            'curtailable_load_count' => $curtailableCount,
            'motor_load_count'       => $motorCount,
            'has_solar'              => $activeSolar->isNotEmpty(),
            'has_battery'            => $activeBatteries->isNotEmpty(),
            'has_generator'          => $project->generatorLines->isNotEmpty(),
            'has_utility'            => $project->utilityLines->isNotEmpty(),
            'total_demand_kva'       => round($totalVa  / 1000, 2),
            'total_demand_kw'        => round($totalW   / 1000, 2),
            'total_demand_kvar'      => round($totalVar / 1000, 2),
            'system_power_factor'    => $sysPf,
            'solar_capacity_kw'      => round($activeSolar->sum('capacity_kw'), 2),
            'battery_capacity_kwh'   => round($activeBatteries->sum('usable_capacity_kwh'), 2),
            'generator_capacity_kw'  => round($project->generatorLines->sum(fn($g) => (float) $g->power / 1000), 2),
            'utility_capacity_kva'   => round($project->utilityLines->sum(fn($u) => (float) $u->power / 1000), 2),
            'api_endpoint_count'     => 56,
            'standards_used'         => [
                'IEC 60364-8-1', 'BS 7671', 'PENRA', 'NEC Article 430',
                'IEC 60947-4',   'IEC 60831', 'IEC 61675-3',
                'Spencer (1971)', 'NASA POWER v2', 'CIBSE Guide C',
            ],
        ]);
    }
}
