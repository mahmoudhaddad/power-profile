<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\BuildingComponent;
use App\Models\Floor;
use App\Models\FloorComponent;
use App\Models\Project;
use App\Models\Room;
use App\Models\RoomComponent;
use App\Services\DiversityFactorService;
use App\Services\SolarIrradianceService;
use App\Services\SocketDemandService;
use Illuminate\Http\Request;

class TotalPowerController extends Controller
{
    // IEC 60364-8-1 project-level diversity factor (building → project).
    // Floor/room-level DFs are per-building-type via DiversityFactorService.
    private const DF_PROJECT = 0.7;

    // System constants for reactive power and capacitor bank sizing.
    private const VOLTAGE_3PHASE_LL       = 400;   // V, 3-phase line-to-line (IEC)
    private const VOLTAGE_1PHASE          = 230;   // V, 1-phase line-to-neutral (IEC)
    private const SYSTEM_FREQUENCY        = 50;    // Hz
    private const TARGET_POWER_FACTOR     = 0.95;  // PENRA / IEC target PF
    private const PF_CORRECTION_THRESHOLD = 0.85;  // below this, correction is recommended
    private const CAPACITOR_BANK_STEP     = 0.5;   // kVAR, standard manufactured increment

    // NEC/IEC motor inrush: size the single largest motor at 125% of its rated VA.
    private const INRUSH_MULTIPLIER = 1.25;

    public function __construct(private SocketDemandService $sockets) {}

    /**
     * Scan one or more component query builders for motor-type loads (is_motor = true)
     * and return the one with the highest per-unit VA (the inrush candidate).
     *
     * "Per-unit VA" = the power column value for a single unit (power stores VA).
     * We compare per-unit VA so that a single large motor is always preferred over
     * many small ones of the same type.
     */
    private function findLargestMotor(array $queries): ?array
    {
        $best = null;

        foreach ($queries as $query) {
            $motors = $query
                ->whereHas('componentType', fn($q) => $q->where('is_motor', true))
                ->with('componentType')
                ->get(['power', 'power_factor', 'quantity', 'component_type_id']);

            foreach ($motors as $m) {
                $perUnitVa = (float) $m->power;
                if ($best === null || $perUnitVa > $best['per_unit_va']) {
                    $pf = max((float) ($m->power_factor ?? 1), 0.01);
                    $best = [
                        'name'        => $m->componentType->name ?? 'Motor',
                        'per_unit_va' => $perUnitVa,
                        'quantity'    => (int) $m->quantity,
                        'pf'          => $pf,
                        'base_va'     => round($perUnitVa * (int) $m->quantity, 2),
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * Apply the NEC/IEC 125% inrush rule to the max power vectors.
     *
     * The largest motor was already counted at 100% inside $maxW / $maxQ.
     * This adds the remaining 25% (INRUSH_MULTIPLIER − 1) delta to both
     * vectors so that max_va and max_kvar are recomputed correctly upstream.
     *
     * Returns the inrush_applied / inrush_component fields ready for the response.
     */
    private function applyInrush(?array $motor, float &$maxW, float &$maxQ): array
    {
        if (! $motor) {
            return ['inrush_applied' => false, 'inrush_component' => null];
        }

        $delta   = self::INRUSH_MULTIPLIER - 1.0;                      // 0.25
        $addVa   = $motor['per_unit_va'] * $motor['quantity'] * $delta; // extra VA
        $addW    = $addVa * $motor['pf'];                               // extra P
        $addQ    = $addVa * sqrt(max(0.0, 1.0 - $motor['pf'] ** 2));   // extra Q

        $maxW += $addW;
        $maxQ += $addQ;

        $sizedVa    = $motor['base_va'] * self::INRUSH_MULTIPLIER;
        $additionVa = round($sizedVa - $motor['base_va'], 2);

        return [
            'inrush_applied'    => true,
            'inrush_component'  => [
                'name'               => $motor['name'],
                'per_unit_va'        => round($motor['per_unit_va'], 2),
                'quantity'           => $motor['quantity'],
                'base_va'            => round($motor['base_va'], 2),
                'sized_va'           => round($sizedVa, 2),
                'inrush_multiplier'  => self::INRUSH_MULTIPLIER,
                'inrush_addition_va' => $additionVa,
                'note'               => 'Largest motor sized at 125% for locked-rotor inrush current per IEC/NEC standard.',
            ],
        ];
    }

    private function projectSources(Project $project): array
    {
        $buildingsArea = $project->buildings()->sum('area');
        $solarComputed = SolarIrradianceService::estimateCapacityW((float) $buildingsArea);

        return [
            'solar_computed' => round($solarComputed, 2),
        ];
    }

    /**
     * Sum power for a component query with group-max optimisation.
     *
     * Returns:
     *   va / w               – optimised totals
     *   max_va / max_w       – unoptimised totals (sum of every component)
     *   {priority}_va/w      – optimised per priority (critical/essential/normal)
     *   {priority}_max_va/w  – unoptimised per priority
     *
     * withPriority=false → all components are treated as 'normal'.
     */
    /** Zero-filled power result for use as a merge accumulator. */
    private function emptyPower(): array
    {
        $pKeys  = ['critical', 'essential', 'normal'];
        $result = ['va' => 0.0, 'w' => 0.0, 'q' => 0.0, 'max_va' => 0.0, 'max_w' => 0.0, 'max_q' => 0.0];
        foreach ($pKeys as $p) {
            $result["{$p}_va"]     = 0.0;
            $result["{$p}_w"]      = 0.0;
            $result["{$p}_max_va"] = 0.0;
            $result["{$p}_max_w"]  = 0.0;
        }
        return $result;
    }

    /**
     * Sum power for a component query with group-max optimisation.
     *
     * $df may be a float (applied uniformly) or a callable(int $entityId): float
     * (looked up per-component for per-entity diversity factors).
     */
    private function sumPowerWithGroups($query, string $entityKey, bool $withPriority = false, mixed $df = 1.0): array
    {
        $columns = ['power', 'power_factor', 'quantity', 'group_name', $entityKey];
        if ($withPriority) {
            $columns[] = 'priority';
        }

        $components = $query->get($columns);

        $pKeys = ['critical', 'essential', 'normal'];

        $maxVA   = 0.0;
        $maxW    = 0.0;
        $maxQ    = 0.0;
        $vaTotal = 0.0;
        $wTotal  = 0.0;
        $qTotal  = 0.0;

        $maxByP   = array_fill_keys($pKeys, ['va' => 0.0, 'w' => 0.0, 'q' => 0.0]);
        $ungrpByP = array_fill_keys($pKeys, ['va' => 0.0, 'w' => 0.0, 'q' => 0.0]);
        $groups   = [];

        foreach ($components as $c) {
            $pf = max((float) ($c->power_factor ?? 1), 0.01);
            $va = (float) $c->power * (int) $c->quantity;         // S = VA_rated × qty  (power column stores VA)
            $w  = $va * $pf;                                       // P = S × PF
            $q  = $pf < 1.0 ? $w * tan(acos(min(1.0, $pf))) : 0.0; // Q = P·tan(arccos(PF))
            $priority = $withPriority ? ($c->priority ?? 'normal') : 'normal';

            $maxVA                   += $va;
            $maxW                    += $w;
            $maxQ                    += $q;
            $maxByP[$priority]['va'] += $va;
            $maxByP[$priority]['w']  += $w;
            $maxByP[$priority]['q']  += $q;

            $resolvedDf  = is_callable($df) ? $df((int) $c->{$entityKey}) : (float) $df;
            $effectiveDf = ($priority === 'critical') ? 1.0 : $resolvedDf;
            $wd = $w * $effectiveDf;
            $qd = $q * $effectiveDf;

            if (!$c->group_name) {
                $vaTotal += $va;
                $wTotal  += $wd;
                $qTotal  += $qd;
                $ungrpByP[$priority]['va'] += $va;
                $ungrpByP[$priority]['w']  += $wd;
                $ungrpByP[$priority]['q']  += $qd;
            } else {
                $key = $c->{$entityKey} . '|' . $c->group_name;
                if (!isset($groups[$key]) || $va > $groups[$key]['va']) {
                    $groups[$key] = ['va' => $va, 'w' => $wd, 'q' => $qd, 'priority' => $priority];
                }
            }
        }

        $grpByP = array_fill_keys($pKeys, ['va' => 0.0, 'w' => 0.0, 'q' => 0.0]);
        foreach ($groups as $g) {
            $vaTotal          += $g['va'];
            $wTotal           += $g['w'];
            $qTotal           += $g['q'];
            $p = $g['priority'];
            $grpByP[$p]['va'] += $g['va'];
            $grpByP[$p]['w']  += $g['w'];
            $grpByP[$p]['q']  += $g['q'];
        }

        $result = [
            'va'     => round($vaTotal, 2),
            'w'      => round($wTotal,  2),
            'q'      => round($qTotal,  4),
            'max_va' => round($maxVA,   2),
            'max_w'  => round($maxW,    2),
            'max_q'  => round($maxQ,    4),
        ];

        foreach ($pKeys as $p) {
            $optW  = $ungrpByP[$p]['w'] + $grpByP[$p]['w'];
            $optQ  = $ungrpByP[$p]['q'] + $grpByP[$p]['q'];
            $maxPw = $maxByP[$p]['w'];
            $maxPq = $maxByP[$p]['q'];
            $result["{$p}_va"]     = round(sqrt($optW ** 2 + $optQ ** 2), 2);
            $result["{$p}_w"]      = round($optW, 2);
            $result["{$p}_max_va"] = round(sqrt($maxPw ** 2 + $maxPq ** 2), 2);
            $result["{$p}_max_w"]  = round($maxPw, 2);
        }

        return $result;
    }

    /** Aggregate multiple sumPowerWithGroups results into one. */
    private function mergePower(array ...$parts): array
    {
        $keys = ['va', 'w', 'q', 'max_va', 'max_w', 'max_q',
                 'critical_va', 'critical_w', 'critical_max_va', 'critical_max_w',
                 'essential_va', 'essential_w', 'essential_max_va', 'essential_max_w',
                 'normal_va', 'normal_w', 'normal_max_va', 'normal_max_w'];

        $result = array_fill_keys($keys, 0.0);
        foreach ($parts as $part) {
            foreach ($keys as $k) {
                $result[$k] += $part[$k] ?? 0.0;
            }
        }
        return $result;
    }


    /**
     * Reactive power / capacitor bank fields (IEC 60364-8-1 / PENRA).
     *
     * Power triangle (vector aggregation):
     *   S_sys = sqrt(P² + Q²),  PF_sys = P / S_sys
     *   Q_cap = Q_sys − P·tan(arccos(PF_target))
     *
     * Capacitor bank — Delta (Δ) configuration:
     *   Each phase capacitor sees the full V_LL (400 V).
     *   C_phase = (Q_cap / 3) / (2π·f·V_LL²)   [F → μF]
     *
     * Line current:
     *   3-phase: I = S / (√3 × 400)
     *   1-phase: I = S / 230
     */
    private function reactivePowerFields(
        float $totalVa, float $totalW, float $totalQ,
        float $maxVa,   float $maxW,   float $maxQ,
        bool  $is3Phase = true,
    ): array {
        // Display-only kVAR values (diversified and undiversified)
        $totalKvar = round($totalQ / 1000, 2);
        $maxKvar   = round($maxQ   / 1000, 2);

        // System PF derived exclusively from the canonical total_va already
        // computed and returned in the response — not re-derived from rounded W.
        $pfSys = $totalVa > 0 ? min(1.0, round($totalW / $totalVa, 3)) : 1.0;

        $needsCorrection = $pfSys < self::PF_CORRECTION_THRESHOLD;

        // Line current helper (3-phase or 1-phase)
        $sqrt3     = sqrt(3);
        $currentOf = fn(float $s) => $is3Phase
            ? round($s / ($sqrt3 * self::VOLTAGE_3PHASE_LL), 2)
            : round($s / self::VOLTAGE_1PHASE, 2);

        // kVAR remaining at target PF (display, regardless of correction)
        $qTargetVar = $totalW * tan(acos(self::TARGET_POWER_FACTOR));
        $kvarAfter  = round(max(0.0, $qTargetVar) / 1000, 2);

        if (! $needsCorrection) {
            return [
                'total_kvar'                  => $totalKvar,
                'max_kvar'                    => $maxKvar,
                'kvar_after_correction'       => $kvarAfter,
                'system_power_factor'         => $pfSys,
                'pf_correction_recommended'   => false,
                'capacitor_bank_kvar'         => null,
                'capacitor_bank_uf'           => null,
                'capacitor_bank_target_pf'    => null,
                'current_before_correction_a' => null,
                'current_after_correction_a'  => null,
                'current_reduction_percent'   => null,
                'correction_note'             => null,
            ];
        }

        // ── Capacitor bank block ─────────────────────────────────────────────
        // ALL variables below use ONLY $totalW (P) and $totalQ (Q) —
        // the same diversified vectors that produced $totalVa and $pfSys above.

        // Q to remove, rounded up to nearest standard 0.5 kVAR step
        $qCapVar  = $totalQ - $qTargetVar;
        $bankKvar = ceil(($qCapVar / 1000) / self::CAPACITOR_BANK_STEP) * self::CAPACITOR_BANK_STEP;
        $qCapVAR  = $bankKvar * 1000;

        // Delta (Δ) configuration: each phase capacitor sees full V_LL
        $qPerPhase = $qCapVAR / 3;
        $bankUf    = round(
            ($qPerPhase / (2 * M_PI * self::SYSTEM_FREQUENCY * self::VOLTAGE_3PHASE_LL ** 2)) * 1e6,
            2
        );

        // Post-correction geometry
        $qNetVar = max(0.0, $totalQ - $qCapVAR);
        $sAfter  = sqrt($totalW ** 2 + $qNetVar ** 2);
        $pfAfter = $sAfter > 0 ? min(1.0, round($totalW / $sAfter, 3)) : 1.0;

        // Currents — $totalVa is the canonical S_before (not re-derived)
        $iBefore   = $currentOf($totalVa);
        $iAfter    = $currentOf($sAfter);
        $reduction = $iBefore > 0
            ? round(($iBefore - $iAfter) / $iBefore * 100, 1)
            : 0.0;

        $phaseLabel = $is3Phase ? '3-phase Δ' : '1-phase';
        $note = 'Install a ' . $bankKvar . ' kVAR (' . $phaseLabel . ', ' . $bankUf . ' μF/phase) '
            . 'capacitor bank to correct PF from ' . round($pfSys, 3)
            . ' to ' . round($pfAfter, 3)
            . ', reducing line current by ' . $reduction . '%.';

        return [
            'total_kvar'                  => $totalKvar,
            'max_kvar'                    => $maxKvar,
            'kvar_after_correction'       => round($qNetVar / 1000, 2),
            'system_power_factor'         => $pfSys,
            'pf_correction_recommended'   => true,
            'capacitor_bank_kvar'         => round($bankKvar, 2),
            'capacitor_bank_uf'           => $bankUf,
            'capacitor_bank_target_pf'    => $pfAfter,
            'current_before_correction_a' => $iBefore,
            'current_after_correction_a'  => $iAfter,
            'current_reduction_percent'   => $reduction,
            'correction_note'             => $note,
        ];
    }

    public function project(Request $request, Project $project)
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Build type maps for per-entity DF resolution (3 extra queries, no N+1).
        $projectBuildings = $project->buildings()->select('id', 'type')->get();
        $bTypeMap = $projectBuildings->pluck('type', 'id');

        $allFloors = Floor::whereIn('building_id', $projectBuildings->pluck('id'))
            ->select('id', 'building_id')->get();
        $fToBMap = $allFloors->pluck('building_id', 'id');

        $allRooms = Room::whereIn('floor_id', $allFloors->pluck('id'))
            ->select('id', 'floor_id', 'type')->get();
        $rToBMap  = $allRooms->mapWithKeys(fn($r) => [$r->id => $fToBMap->get($r->floor_id)]);
        $rTypeMap = $allRooms->pluck('type', 'id');

        $floorDfFn = function(int $fid) use ($fToBMap, $bTypeMap): float {
            $bType = $bTypeMap->get($fToBMap->get($fid));
            return DiversityFactorService::buildingDfs($bType)['floor_to_building'] * self::DF_PROJECT;
        };
        $roomDfFn = function(int $rid) use ($rToBMap, $bTypeMap, $rTypeMap): float {
            $bType = $bTypeMap->get($rToBMap->get($rid));
            $rType = $rTypeMap->get($rid);
            $dfs   = DiversityFactorService::buildingDfs($bType);
            return DiversityFactorService::roomDf($rType) * $dfs['room_to_floor'] * $dfs['floor_to_building'] * self::DF_PROJECT;
        };

        $own      = $this->sumPowerWithGroups($project->components(), 'project_id', true);
        $building = $this->sumPowerWithGroups(
            BuildingComponent::whereHas('building', fn($q) => $q->where('project_id', $project->id)),
            'building_id', true, self::DF_PROJECT
        );
        $floor    = $this->sumPowerWithGroups(
            FloorComponent::whereHas('floor.building', fn($q) => $q->where('project_id', $project->id)),
            'floor_id', true, $floorDfFn
        );
        $room     = $this->sumPowerWithGroups(
            RoomComponent::whereHas('room.floor.building', fn($q) => $q->where('project_id', $project->id)),
            'room_id', true, $roomDfFn
        );

        $comp = $this->mergePower($own, $building, $floor, $room);
        $sd   = $this->sockets->projectResult($project);

        $socketDemand    = $sd['demand_va'];
        $socketConnected = $sd['connected_va'];

        $sinPf   = sin(acos(self::TARGET_POWER_FACTOR));
        $totalW  = $comp['w'] + $socketDemand * self::TARGET_POWER_FACTOR;
        $totalQ  = $comp['q'] + $socketDemand * $sinPf;
        $totalVa = round(sqrt($totalW ** 2 + $totalQ ** 2), 2);
        $totalW  = round($totalW, 2);
        $maxW    = $comp['max_w'] + $socketConnected * self::TARGET_POWER_FACTOR;
        $maxQ    = $comp['max_q'] + $socketConnected * $sinPf;

        // Motor inrush: find largest motor across all component levels, apply 125% to max vectors.
        $motor  = $this->findLargestMotor([
            $project->components(),
            BuildingComponent::whereHas('building', fn($q) => $q->where('project_id', $project->id)),
            FloorComponent::whereHas('floor.building', fn($q) => $q->where('project_id', $project->id)),
            RoomComponent::whereHas('room.floor.building', fn($q) => $q->where('project_id', $project->id)),
        ]);
        $inrush = $this->applyInrush($motor, $maxW, $maxQ);
        $maxVa  = round(sqrt($maxW ** 2 + $maxQ ** 2), 2);
        $maxW   = round($maxW, 2);

        $utilLine = $project->utilityLines()->select('phases')->first();
        $is3Phase = ($utilLine?->phases ?? '3phase') !== '1phase';

        // ── Battery storage summary ──────────────────────────────────────────
        $batteries      = $project->batteries()->where('is_active', true)->get();
        $batteryStorage = null;

        if ($batteries->isNotEmpty()) {
            $totalNominal   = $batteries->sum(fn($b) => $b->nominal_capacity_kwh);
            $totalUsable    = $batteries->sum(fn($b) => $b->usable_capacity_kwh);
            $totalAvailable = $batteries->sum(fn($b) => $b->current_available_kwh);
            $criticalKw     = $comp['critical_w'] / 1000;
            $optimizedKw    = $totalW             / 1000;

            $bckpCritical  = $criticalKw  > 0 ? round($totalUsable    / $criticalKw,  2) : null;
            $bckpOptimized = $optimizedKw > 0 ? round($totalAvailable / $optimizedKw, 2) : null;

            $batteryStorage = [
                'bank_count'          => $batteries->count(),
                'total_nominal_kwh'   => round($totalNominal,   2),
                'total_usable_kwh'    => round($totalUsable,    2),
                'total_available_kwh' => round($totalAvailable, 2),
                'chemistry_breakdown' => $batteries->groupBy('chemistry')->map(fn($g) => $g->count())->toArray(),
                'needs_attention'     => $batteries->contains(fn($b) => $b->health_status === 'replace'),
                'runtime_summary'     => [
                    'backup_hours_at_critical_load_full'     => $bckpCritical,
                    'backup_hours_at_optimized_load_current' => $bckpOptimized,
                ],
            ];
        }

        return response()->json(array_merge([
            'total_va'         => $totalVa,
            'total'            => $totalW,
            'max_va'           => $maxVa,
            'max_w'            => $maxW,

            'critical_va'      => $comp['critical_va'],
            'critical_w'       => $comp['critical_w'],
            'critical_max_va'  => $comp['critical_max_va'],
            'critical_max_w'   => $comp['critical_max_w'],
            'essential_va'     => $comp['essential_va'],
            'essential_w'      => $comp['essential_w'],
            'essential_max_va' => $comp['essential_max_va'],
            'essential_max_w'  => $comp['essential_max_w'],
            'normal_va'        => $comp['normal_va'],
            'normal_w'         => $comp['normal_w'],
            'normal_max_va'    => $comp['normal_max_va'],
            'normal_max_w'     => $comp['normal_max_w'],

            'socket_demand_va'    => $socketDemand,
            'socket_connected_va' => $socketConnected,

            'own'         => $own['w'],      'own_va'      => $own['va'],
            'building'    => $building['w'], 'building_va' => $building['va'],
            'floor'       => $floor['w'],    'floor_va'    => $floor['va'],
            'room'        => $room['w'],     'room_va'     => $room['va'],
            'critical'    => $comp['critical_w'],

            'battery_storage' => $batteryStorage,
        ], $inrush,
           $this->reactivePowerFields($totalVa, $totalW, $totalQ, $maxVa, $maxW, $maxQ, $is3Phase),
           $this->projectSources($project)));
    }

    public function building(Request $request, Building $building)
    {
        if (! $building->project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $bDfs = DiversityFactorService::buildingDfs($building->type);

        $buildingFloorIds = $building->floors()->pluck('id');
        $buildingRooms    = Room::whereIn('floor_id', $buildingFloorIds)->select('id', 'type')->get();
        $rTypeMap_b       = $buildingRooms->pluck('type', 'id');

        $roomDfFn_b = function(int $rid) use ($bDfs, $rTypeMap_b): float {
            return DiversityFactorService::roomDf($rTypeMap_b->get($rid))
                * $bDfs['room_to_floor'] * $bDfs['floor_to_building'];
        };

        $own   = $this->sumPowerWithGroups($building->components(), 'building_id', true);
        $floor = $this->sumPowerWithGroups(
            FloorComponent::whereHas('floor', fn($q) => $q->where('building_id', $building->id)),
            'floor_id', true, $bDfs['floor_to_building']
        );
        $room  = $this->sumPowerWithGroups(
            RoomComponent::whereHas('room.floor', fn($q) => $q->where('building_id', $building->id)),
            'room_id', true, $roomDfFn_b
        );

        $comp = $this->mergePower($own, $floor, $room);
        $sd   = $this->sockets->buildingResult($building);

        $socketDemand    = $sd['demand_va'];
        $socketConnected = $sd['connected_va'];

        $sinPf   = sin(acos(self::TARGET_POWER_FACTOR));
        $totalW  = $comp['w'] + $socketDemand * self::TARGET_POWER_FACTOR;
        $totalQ  = $comp['q'] + $socketDemand * $sinPf;
        $totalVa = round(sqrt($totalW ** 2 + $totalQ ** 2), 2);
        $totalW  = round($totalW, 2);
        $maxW    = $comp['max_w'] + $socketConnected * self::TARGET_POWER_FACTOR;
        $maxQ    = $comp['max_q'] + $socketConnected * $sinPf;

        // Motor inrush: find largest motor across all component levels, apply 125% to max vectors.
        $motor  = $this->findLargestMotor([
            $building->components(),
            FloorComponent::whereHas('floor', fn($q) => $q->where('building_id', $building->id)),
            RoomComponent::whereHas('room.floor', fn($q) => $q->where('building_id', $building->id)),
        ]);
        $inrush = $this->applyInrush($motor, $maxW, $maxQ);
        $maxVa  = round(sqrt($maxW ** 2 + $maxQ ** 2), 2);
        $maxW   = round($maxW, 2);

        $utilLine = $building->project->utilityLines()->select('phases')->first();
        $is3Phase = ($utilLine?->phases ?? '3phase') !== '1phase';

        return response()->json(array_merge([
            'total_va'         => $totalVa,
            'total'            => $totalW,
            'max_va'           => $maxVa,
            'max_w'            => $maxW,

            'critical_va'      => $comp['critical_va'],
            'critical_w'       => $comp['critical_w'],
            'critical_max_va'  => $comp['critical_max_va'],
            'critical_max_w'   => $comp['critical_max_w'],
            'essential_va'     => $comp['essential_va'],
            'essential_w'      => $comp['essential_w'],
            'essential_max_va' => $comp['essential_max_va'],
            'essential_max_w'  => $comp['essential_max_w'],
            'normal_va'        => $comp['normal_va'],
            'normal_w'         => $comp['normal_w'],
            'normal_max_va'    => $comp['normal_max_va'],
            'normal_max_w'     => $comp['normal_max_w'],

            'socket_demand_va'           => $socketDemand,
            'socket_connected_va'        => $socketConnected,
            'socket_sum_floor_demand_va' => $sd['sum_floor_demand_va'],
            'socket_coincidence_factor'  => $sd['coincidence_factor'],

            'own'      => $own['w'],   'own_va'   => $own['va'],
            'floor'    => $floor['w'], 'floor_va' => $floor['va'],
            'room'     => $room['w'],  'room_va'  => $room['va'],
        ], $inrush,
           $this->reactivePowerFields($totalVa, $totalW, $totalQ, $maxVa, $maxW, $maxQ, $is3Phase),
           $this->projectSources($building->project)));
    }

    public function floor(Request $request, Floor $floor)
    {
        if (! $floor->building->project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $bDfs = DiversityFactorService::buildingDfs($floor->building->type ?? null);

        $floorRooms = Room::where('floor_id', $floor->id)->select('id', 'type')->get();
        $rTypeMap_f = $floorRooms->pluck('type', 'id');

        $roomDfFn_f = function(int $rid) use ($bDfs, $rTypeMap_f): float {
            return DiversityFactorService::roomDf($rTypeMap_f->get($rid)) * $bDfs['room_to_floor'];
        };

        $own  = $this->sumPowerWithGroups($floor->components(), 'floor_id', true);
        $room = $this->sumPowerWithGroups(
            RoomComponent::whereHas('room', fn($q) => $q->where('floor_id', $floor->id)),
            'room_id', true, $roomDfFn_f
        );

        $comp = $this->mergePower($own, $room);
        $sd   = $this->sockets->floorResult($floor);

        $socketDemand    = $sd['demand_va'];
        $socketConnected = $sd['connected_va'];

        $sinPf   = sin(acos(self::TARGET_POWER_FACTOR));
        $totalW  = $comp['w'] + $socketDemand * self::TARGET_POWER_FACTOR;
        $totalQ  = $comp['q'] + $socketDemand * $sinPf;
        $totalVa = round(sqrt($totalW ** 2 + $totalQ ** 2), 2);
        $totalW  = round($totalW, 2);
        $maxW    = $comp['max_w'] + $socketConnected * self::TARGET_POWER_FACTOR;
        $maxQ    = $comp['max_q'] + $socketConnected * $sinPf;

        // Motor inrush: find largest motor across floor + room components, apply 125% to max vectors.
        $motor  = $this->findLargestMotor([
            $floor->components(),
            RoomComponent::whereHas('room', fn($q) => $q->where('floor_id', $floor->id)),
        ]);
        $inrush = $this->applyInrush($motor, $maxW, $maxQ);
        $maxVa  = round(sqrt($maxW ** 2 + $maxQ ** 2), 2);
        $maxW   = round($maxW, 2);

        $utilLine = $floor->building->project->utilityLines()->select('phases')->first();
        $is3Phase = ($utilLine?->phases ?? '3phase') !== '1phase';

        return response()->json(array_merge([
            'total_va'         => $totalVa,
            'total'            => $totalW,
            'max_va'           => $maxVa,
            'max_w'            => $maxW,

            'critical_va'      => $comp['critical_va'],
            'critical_w'       => $comp['critical_w'],
            'critical_max_va'  => $comp['critical_max_va'],
            'critical_max_w'   => $comp['critical_max_w'],
            'essential_va'     => $comp['essential_va'],
            'essential_w'      => $comp['essential_w'],
            'essential_max_va' => $comp['essential_max_va'],
            'essential_max_w'  => $comp['essential_max_w'],
            'normal_va'        => $comp['normal_va'],
            'normal_w'         => $comp['normal_w'],
            'normal_max_va'    => $comp['normal_max_va'],
            'normal_max_w'     => $comp['normal_max_w'],

            'socket_outlets'      => $sd['outlets'],
            'socket_connected_va' => $socketConnected,
            'socket_demand_va'    => $socketDemand,

            'own'  => $own['w'],  'own_va'  => $own['va'],
            'room' => $room['w'], 'room_va' => $room['va'],
        ], $inrush,
           $this->reactivePowerFields($totalVa, $totalW, $totalQ, $maxVa, $maxW, $maxQ, $is3Phase),
           $this->projectSources($floor->building->project)));
    }

    public function room(Request $request, Room $room)
    {
        if (! $room->floor->building->project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $own = $this->sumPowerWithGroups($room->components(), 'room_id', true);
        $sd  = $this->sockets->roomResult($room);

        $socketDemand    = $sd['demand_va'];
        $socketConnected = $sd['connected_va'];

        $sinPf   = sin(acos(self::TARGET_POWER_FACTOR));
        $totalW  = $own['w'] + $socketDemand * self::TARGET_POWER_FACTOR;
        $totalQ  = $own['q'] + $socketDemand * $sinPf;
        $totalVa = round(sqrt($totalW ** 2 + $totalQ ** 2), 2);
        $totalW  = round($totalW, 2);
        $maxW    = $own['max_w'] + $socketConnected * self::TARGET_POWER_FACTOR;
        $maxQ    = $own['max_q'] + $socketConnected * $sinPf;

        // Motor inrush: find largest motor in this room, apply 125% to max vectors.
        $motor  = $this->findLargestMotor([$room->components()]);
        $inrush = $this->applyInrush($motor, $maxW, $maxQ);
        $maxVa  = round(sqrt($maxW ** 2 + $maxQ ** 2), 2);
        $maxW   = round($maxW, 2);

        $utilLine = $room->floor->building->project->utilityLines()->select('phases')->first();
        $is3Phase = ($utilLine?->phases ?? '3phase') !== '1phase';

        return response()->json(array_merge([
            'total_va'         => $totalVa,
            'total'            => $totalW,
            'max_va'           => $maxVa,
            'max_w'            => $maxW,

            'critical_va'      => $own['critical_va'],
            'critical_w'       => $own['critical_w'],
            'critical_max_va'  => $own['critical_max_va'],
            'critical_max_w'   => $own['critical_max_w'],
            'essential_va'     => $own['essential_va'],
            'essential_w'      => $own['essential_w'],
            'essential_max_va' => $own['essential_max_va'],
            'essential_max_w'  => $own['essential_max_w'],
            'normal_va'        => $own['normal_va'],
            'normal_w'         => $own['normal_w'],
            'normal_max_va'    => $own['normal_max_va'],
            'normal_max_w'     => $own['normal_max_w'],

            'socket_outlets'      => $sd['outlets'],
            'socket_connected_va' => $socketConnected,
            'socket_demand_va'    => $socketDemand,

            'own'    => $own['w'], 'own_va' => $own['va'],
        ], $inrush,
           $this->reactivePowerFields($totalVa, $totalW, $totalQ, $maxVa, $maxW, $maxQ, $is3Phase),
           $this->projectSources($room->floor->building->project)));
    }
}
