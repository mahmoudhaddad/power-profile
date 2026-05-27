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
use App\Services\SocketDemandService;
use Illuminate\Http\Request;

class TotalPowerController extends Controller
{
    // IEC 60364-8-1 diversity factors applied when aggregating upward.
    // Room level is 1.0 (leaf node — no constant needed).
    private const DF_FLOOR    = 0.9;
    private const DF_BUILDING = 0.8;
    private const DF_PROJECT  = 0.7;

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
        $solarComputed = $buildingsArea * 0.17 * 1000 * 0.75;

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
    private function sumPowerWithGroups($query, string $entityKey, bool $withPriority = false, float $dfMultiplier = 1.0): array
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

            $effectiveDf = ($priority === 'critical') ? 1.0 : $dfMultiplier;
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
     * Returns [entity_id => ['w' => float, 'q' => float]] with group-max (by VA) applied per entity.
     * Only orthogonal vectors P and Q are accumulated — VA is computed momentarily for comparison only.
     */
    private function vaPerEntity($query, string $entityKey): array
    {
        $rows = $query->get(['power', 'power_factor', 'quantity', 'group_name', $entityKey]);

        $raw = [];
        foreach ($rows as $c) {
            $id = $c->{$entityKey};
            $pf = max((float)($c->power_factor ?? 1), 0.01);
            $va = (float)$c->power * (int)$c->quantity;   // S = VA_rated × qty (power stores VA)
            $w  = $va * $pf;                               // P = S × PF
            $q  = $pf < 1.0 ? $w * tan(acos(min(1.0, $pf))) : 0.0;

            if (!isset($raw[$id])) {
                $raw[$id] = ['ungrouped' => ['w' => 0.0, 'q' => 0.0], 'groups' => []];
            }

            if (!$c->group_name) {
                $raw[$id]['ungrouped']['w'] += $w;
                $raw[$id]['ungrouped']['q'] += $q;
            } else {
                $key = $id . '|' . $c->group_name;
                if (!isset($raw[$id]['groups'][$key]) || $va > $raw[$id]['groups'][$key]['va']) {
                    $raw[$id]['groups'][$key] = ['va' => $va, 'w' => $w, 'q' => $q];
                }
            }
        }

        $result = [];
        foreach ($raw as $id => $data) {
            $w = $data['ungrouped']['w'];
            $q = $data['ungrouped']['q'];
            foreach ($data['groups'] as $g) {
                $w += $g['w'];
                $q += $g['q'];
            }
            $result[$id] = ['w' => $w, 'q' => $q];
        }
        return $result;
    }

    /** Sums orthogonal P and Q vectors from a vaPerEntity result. */
    private function sumEntityVa(array $perEntity): array
    {
        $w = 0.0;
        $q = 0.0;
        foreach ($perEntity as $e) {
            $w += $e['w'];
            $q += $e['q'] ?? 0.0;
        }
        return ['w' => $w, 'q' => $q];
    }

    /**
     * Diversify floor P/Q vectors: floor_own + Σ(room P/Q) × DF_FLOOR.
     * Only orthogonal vectors are tracked — VA is derived at the final stage.
     */
    private function computeFloorsDivVa(array $floorOwnVas, array $roomVas, array $roomToFloor): array
    {
        $floorIds = array_unique(array_merge(array_keys($floorOwnVas), array_values($roomToFloor)));

        $result = [];
        foreach ($floorIds as $fid) {
            $roomW = 0.0;
            $roomQ = 0.0;
            foreach ($roomToFloor as $rid => $rfid) {
                if ($rfid == $fid && isset($roomVas[$rid])) {
                    $roomW += $roomVas[$rid]['w'];
                    $roomQ += $roomVas[$rid]['q'] ?? 0.0;
                }
            }
            $result[$fid] = [
                'w' => ($floorOwnVas[$fid]['w'] ?? 0.0) + $roomW * self::DF_FLOOR,
                'q' => ($floorOwnVas[$fid]['q'] ?? 0.0) + $roomQ * self::DF_FLOOR,
            ];
        }
        return $result;
    }

    /**
     * Diversify building P/Q vectors: building_own + Σ(floor P/Q) × DF_BUILDING.
     * Only orthogonal vectors are tracked — VA is derived at the final stage.
     */
    private function computeBuildingsDivVa(array $buildingOwnVas, array $floorDivVas, array $floorToBuilding): array
    {
        $buildingIds = array_unique(array_merge(array_keys($buildingOwnVas), array_values($floorToBuilding)));

        $result = [];
        foreach ($buildingIds as $bid) {
            $floorW = 0.0;
            $floorQ = 0.0;
            foreach ($floorToBuilding as $fid => $fbid) {
                if ($fbid == $bid && isset($floorDivVas[$fid])) {
                    $floorW += $floorDivVas[$fid]['w'];
                    $floorQ += $floorDivVas[$fid]['q'] ?? 0.0;
                }
            }
            $result[$bid] = [
                'w' => ($buildingOwnVas[$bid]['w'] ?? 0.0) + $floorW * self::DF_BUILDING,
                'q' => ($buildingOwnVas[$bid]['q'] ?? 0.0) + $floorQ * self::DF_BUILDING,
            ];
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

        $own      = $this->sumPowerWithGroups($project->components(), 'project_id', true);
        $building = $this->sumPowerWithGroups(
            BuildingComponent::whereHas('building', fn($q) => $q->where('project_id', $project->id)),
            'building_id', true, self::DF_PROJECT
        );
        $floor    = $this->sumPowerWithGroups(
            FloorComponent::whereHas('floor.building', fn($q) => $q->where('project_id', $project->id)),
            'floor_id', true, self::DF_BUILDING * self::DF_PROJECT
        );
        $room     = $this->sumPowerWithGroups(
            RoomComponent::whereHas('room.floor.building', fn($q) => $q->where('project_id', $project->id)),
            'room_id', true, self::DF_FLOOR * self::DF_BUILDING * self::DF_PROJECT
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
        ], $inrush,
           $this->reactivePowerFields($totalVa, $totalW, $totalQ, $maxVa, $maxW, $maxQ, $is3Phase),
           $this->projectSources($project)));
    }

    public function building(Request $request, Building $building)
    {
        if (! $building->project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $own   = $this->sumPowerWithGroups($building->components(), 'building_id', true);
        $floor = $this->sumPowerWithGroups(
            FloorComponent::whereHas('floor', fn($q) => $q->where('building_id', $building->id)),
            'floor_id', true, self::DF_BUILDING
        );
        $room  = $this->sumPowerWithGroups(
            RoomComponent::whereHas('room.floor', fn($q) => $q->where('building_id', $building->id)),
            'room_id', true, self::DF_FLOOR * self::DF_BUILDING
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

        $own  = $this->sumPowerWithGroups($floor->components(), 'floor_id', true);
        $room = $this->sumPowerWithGroups(
            RoomComponent::whereHas('room', fn($q) => $q->where('floor_id', $floor->id)),
            'room_id', true, self::DF_FLOOR
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
