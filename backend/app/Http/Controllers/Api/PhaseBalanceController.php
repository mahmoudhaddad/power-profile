<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Project;
use App\Models\Room;
use App\Services\ElectricalDesignService;
use App\Services\SocketDemandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhaseBalanceController extends Controller
{
    private const VOLT              = 230;
    private const WARN_PCT          = 10;
    private const CRIT_PCT          = 20;
    private const SOCKET_ASSUMED_PF = 0.95;

    // Room splitting (IEC 60364-8-1 balanced loading; CIBSE ±10 % target):
    // A room is split when its 1-ph VA exceeds one equal share of the floor total
    // (floor_total / 3).  Minimum VA guard prevents splitting tiny rooms.
    private const PHASE_SPLIT_MIN_VA = 1500.0;

    // IEC phase voltage angles in radians (positive-sequence ABC)
    private const PHASE_ANGLE_A = 0.0;
    private const PHASE_ANGLE_B = M_PI * 2 / 3;   // 120°
    private const PHASE_ANGLE_C = M_PI * 4 / 3;   // 240°

    public function __construct(private readonly ElectricalDesignService $edService) {}

    // ── Project: all buildings ────────────────────────────────────────────────

    public function project(Request $request, Project $project): JsonResponse
    {
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $sockets   = new SocketDemandService();
        $buildings = $project->buildings()->with([
            'components.componentType',
            'floors.components.componentType',
            'floors.rooms.components.componentType',
        ])->get();

        $edResult  = $this->edService->analyzeProject($project);
        $edByBldId = collect($edResult['buildings'])->keyBy('id')->all();

        return response()->json([
            'buildings' => $buildings->map(
                fn($b) => $this->buildingReport($b, $sockets, $edByBldId[$b->id] ?? null)
            )->values(),
        ]);
    }

    // ── Building: actual + optimal + room breakdown ───────────────────────────

    public function building(Request $request, Building $building): JsonResponse
    {
        $project = $building->project;
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $building->loadMissing([
            'components.componentType',
            'floors.components.componentType',
            'floors.rooms.components.componentType',
        ]);

        $edResult   = $this->edService->analyzeProject($building->project);
        $edBuilding = collect($edResult['buildings'])->firstWhere('id', $building->id);

        return response()->json($this->buildingReport($building, new SocketDemandService(), $edBuilding));
    }

    // ── Floor: simple greedy (unchanged behaviour) ────────────────────────────

    public function floor(Request $request, Floor $floor): JsonResponse
    {
        $project = $floor->building->project;
        if (! $project->userRole($request->user()->id)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $loads1ph = [];
        $va3ph    = 0.0;
        $count3ph = 0;

        $floor->loadMissing(['components.componentType']);
        $this->extractSimple($floor->components, $loads1ph, $va3ph, $count3ph);

        $rooms = $floor->rooms()->with(['components.componentType'])->get();
        foreach ($rooms as $room) {
            $this->extractSimple($room->components, $loads1ph, $va3ph, $count3ph);
        }

        $sockets = new SocketDemandService();
        $sd      = $sockets->floorResult($floor);
        if ($sd['demand_va'] > 0) {
            $loads1ph[] = ['name' => 'Sockets', 'va' => (float) $sd['demand_va'],
                           'type' => 'socket', 'pf' => self::SOCKET_ASSUMED_PF];
        }

        return $this->simpleResponse('floor', $floor->id, $floor->name, $loads1ph, $va3ph, $count3ph);
    }

    // ── Assign a room's 1-phase components to a phase ────────────────────────

    public function assignRoom(Request $request, Room $room): JsonResponse
    {
        $project = $room->floor->building->project;
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate(['phase' => 'nullable|in:A,B,C']);
        $phase = $request->input('phase');  // null = clear

        $room->components()->where('phases', '1phase')->update(['phase' => $phase]);

        return response()->json(['message' => 'Phase updated.']);
    }

    // ── Apply greedy-optimal assignment to the entire building ───────────────

    public function applyOptimalBuilding(Request $request, Building $building): JsonResponse
    {
        $project = $building->project;
        if (! in_array($project->userRole($request->user()->id), ['admin', 'main'])) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $building->loadMissing([
            'components.componentType',
            'floors.components.componentType',
            'floors.rooms.components.componentType',
        ]);

        $edResult   = $this->edService->analyzeProject($building->project);
        $edBuilding = collect($edResult['buildings'])->firstWhere('id', $building->id);
        $report     = $this->buildingReport($building, new SocketDemandService(), $edBuilding);

        foreach ($report['block_assignments'] as $ba) {
            $ph = $ba['optimal_phase'];
            if ($ba['type'] === 'room' && $ba['room_id'] !== null) {
                foreach ($building->getRelation('floors') as $floor) {
                    if ($floor->id !== $ba['floor_id']) continue;
                    foreach ($floor->rooms as $room) {
                        if ($room->id === $ba['room_id']) {
                            $room->components()->where('phases', '1phase')->update(['phase' => $ph]);
                            break 2;
                        }
                    }
                }
            } elseif ($ba['type'] === 'room_section' && $ba['room_id'] !== null && ! empty($ba['component_ids'])) {
                // Split room: apply phase only to the specific component IDs in this section.
                // Each section covers a distinct circuit type (SOCKET/LIGHTING/AUXILIARY),
                // so different sections of the same room can land on different phases.
                if ($ph === null) continue;
                foreach ($building->getRelation('floors') as $floor) {
                    if ($floor->id !== $ba['floor_id']) continue;
                    foreach ($floor->rooms as $room) {
                        if ($room->id === $ba['room_id']) {
                            $room->components()
                                ->whereIn('id', $ba['component_ids'])
                                ->where('phases', '1phase')
                                ->update(['phase' => $ph]);
                            break 2;
                        }
                    }
                }
            } elseif ($ba['type'] === 'floor_own' && $ba['floor_id'] !== null) {
                foreach ($building->getRelation('floors') as $floor) {
                    if ($floor->id === $ba['floor_id']) {
                        $floor->components()->where('phases', '1phase')->update(['phase' => $ph]);
                        break;
                    }
                }
            } elseif ($ba['type'] === 'building') {
                $building->components()->where('phases', '1phase')->update(['phase' => $ph]);
            }
            // 'socket' blocks are virtual (no DB record) — skip
        }

        return response()->json(['message' => 'Optimal phase assignment applied.']);
    }

    // ── Core building report ──────────────────────────────────────────────────
    //
    // Derives the "optimal" phase distribution directly from ElectricalDesignService
    // circuit-level phase assignments so that both pages show the same per-phase VA
    // totals and imbalance %.  The "actual" distribution is still read from the saved
    // DB phase column (unchanged behaviour).

    private function buildingReport(
        Building $building,
        SocketDemandService $sockets,
        ?array $edBuilding = null
    ): array {
        // Index ED floors by ID for fast lookup.
        $edFloorById = [];
        if ($edBuilding) {
            foreach ($edBuilding['floors'] as $f) {
                $edFloorById[$f['id']] = $f;
            }
        }

        $blockAssign = [];
        $floorsOut   = [];
        $optVa       = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];

        foreach ($building->getRelation('floors') as $floor) {
            $edFloor = $edFloorById[$floor->id] ?? null;

            // Accumulate building-level phase VA from the ED floor totals.
            // These ARE the circuit-level phase totals shown on the Electrical Design page.
            if ($edFloor) {
                foreach (['A', 'B', 'C'] as $ph) {
                    $optVa[$ph] += (float) ($edFloor['db']['phase_balance_va'][$ph] ?? 0);
                }
            }

            // Map room name → Room model for this floor (name is unique within a floor).
            $nameToRoom = [];
            foreach ($floor->rooms as $room) {
                $nameToRoom[$room->name] = $room;
            }

            // Index 1-phase circuits from ED by room_id and circuit type.
            // roomCircuits[room_id][circuit_type][] = circuit
            $roomCircuits = [];
            if ($edFloor) {
                foreach ($edFloor['circuits'] as $circuit) {
                    if ($circuit['is3ph'] ?? false) continue;
                    $ph = $circuit['phase'] ?? null;
                    if (! $ph || $ph === '3PH') continue;
                    $ct = $circuit['type'];
                    foreach ($circuit['room_names'] ?? [] as $rName) {
                        if (! isset($nameToRoom[$rName])) continue;
                        $rId = $nameToRoom[$rName]->id;
                        $roomCircuits[$rId][$ct][] = $circuit;
                    }
                }

                // Floor-own block (for Apply Optimal on floor-direct components).
                $fownPhaseVa = [];
                foreach ($edFloor['circuits'] as $circuit) {
                    if ($circuit['is3ph'] ?? false) continue;
                    if (! in_array('(Floor direct)', $circuit['room_names'] ?? [])) continue;
                    $ph = $circuit['phase'] ?? null;
                    if ($ph && $ph !== '3PH') {
                        $fownPhaseVa[$ph] = ($fownPhaseVa[$ph] ?? 0.0) + (float) ($circuit['total_va'] ?? 0);
                    }
                }
                if (! empty($fownPhaseVa)) {
                    arsort($fownPhaseVa);
                    $blockAssign[] = [
                        'type'          => 'floor_own',
                        'name'          => 'Floor components – ' . $floor->name,
                        'va'            => round(array_sum($fownPhaseVa), 2),
                        'floor_id'      => $floor->id,
                        'room_id'       => null,
                        'optimal_phase' => array_key_first($fownPhaseVa),
                    ];
                }
            }

            // Build per-room display data and block_assignments.
            $roomsOut = [];
            foreach ($floor->rooms as $room) {
                $r1ph = $this->extract1ph($room->components);
                $rVa  = array_sum(array_column($r1ph, 'va'));

                // Room's own VA by circuit type (from its component list).
                $roomVaByType = [];
                foreach ($r1ph as $item) {
                    $ct = $item['circuit_type'] ?? 'AUXILIARY';
                    $roomVaByType[$ct] = ($roomVaByType[$ct] ?? 0.0) + $item['va'];
                }

                $compIdsByType = $this->getComponentIdsByType($room->components);

                // For each circuit type, derive phase breakdown using circuit VA proportions.
                // roomCTPhases[circuit_type][phase] = room's proportional VA on that phase.
                $roomCTPhases = [];
                $sectionIdx   = 0;
                foreach (['SOCKET', 'LIGHTING', 'AUXILIARY'] as $ct) {
                    $circuits = $roomCircuits[$room->id][$ct] ?? [];
                    if (empty($circuits)) continue;

                    $circPhaseVa    = [];
                    $totalCircuitVa = 0.0;
                    foreach ($circuits as $c) {
                        $ph  = $c['phase'];
                        $cva = (float) ($c['total_va'] ?? 0);
                        $circPhaseVa[$ph] = ($circPhaseVa[$ph] ?? 0.0) + $cva;
                        $totalCircuitVa  += $cva;
                    }
                    if ($totalCircuitVa <= 0) continue;

                    // Split the room's own VA for this type proportionally across phases.
                    $roomTypeVa  = $roomVaByType[$ct] ?? 0.0;
                    $roomVaPerPh = [];
                    foreach ($circPhaseVa as $ph => $cva) {
                        $roomVaPerPh[$ph] = round($roomTypeVa * ($cva / $totalCircuitVa), 2);
                    }
                    $roomCTPhases[$ct] = $roomVaPerPh;

                    // Dominant phase for Apply Optimal (highest circuit VA share).
                    arsort($circPhaseVa);
                    $domPhase = array_key_first($circPhaseVa);

                    if (! empty($compIdsByType[$ct])) {
                        $blockAssign[] = [
                            'type'          => 'room_section',
                            'name'          => $room->name . ' – ' . $ct . ' (' . $floor->name . ')',
                            'va'            => round(array_sum($roomVaPerPh), 2),
                            'floor_id'      => $floor->id,
                            'room_id'       => $room->id,
                            'section_idx'   => $sectionIdx++,
                            'circuit_types' => [$ct],
                            'component_ids' => array_values($compIdsByType[$ct]),
                            'optimal_phase' => $domPhase,
                        ];
                    }
                }

                // Aggregate per-phase VA for the room across all circuit types.
                $allRoomPhaseVa = [];
                foreach ($roomCTPhases as $phaseVas) {
                    foreach ($phaseVas as $ph => $va) {
                        $allRoomPhaseVa[$ph] = ($allRoomPhaseVa[$ph] ?? 0.0) + $va;
                    }
                }
                ksort($allRoomPhaseVa);  // A → B → C

                $isSplit    = count($allRoomPhaseVa) > 1;
                $optPhase   = (! $isSplit && ! empty($allRoomPhaseVa)) ? array_key_first($allRoomPhaseVa) : null;
                $splitSects = [];

                if ($isSplit) {
                    $si = 1;
                    foreach ($allRoomPhaseVa as $ph => $va) {
                        $splitSects[] = ['section' => $si++, 'phase' => $ph, 'va' => round($va, 2)];
                    }
                }

                $roomsOut[] = [
                    'id'             => $room->id,
                    'name'           => $room->name,
                    'va_1ph'         => round($rVa, 2),
                    'actual_phase'   => $this->consensusPhase($room->components),
                    'optimal_phase'  => $optPhase,
                    'is_split'       => $isSplit,
                    'split_sections' => $splitSects,
                ];
            }

            $floorsOut[] = ['id' => $floor->id, 'name' => $floor->name, 'rooms' => $roomsOut];
        }

        // Building-own 1-phase components: assign greedily against the accumulated floor totals.
        $bOwn = $this->extract1ph($building->components);
        usort($bOwn, fn($a, $b) => $b['va'] <=> $a['va']);
        foreach ($bOwn as $load) {
            $ph = array_keys($optVa, min($optVa))[0];
            $optVa[$ph] += $load['va'];
            $blockAssign[] = [
                'type'          => 'building',
                'name'          => 'Building components',
                'va'            => round($load['va'], 2),
                'floor_id'      => null,
                'room_id'       => null,
                'optimal_phase' => $ph,
            ];
        }

        // ── Optimal distribution ───────────────────────────────────────────────
        $total1phVa = array_sum($optVa);
        $optDist    = [];
        foreach (['A', 'B', 'C'] as $ph) {
            $pct = $total1phVa > 0 ? ($optVa[$ph] / $total1phVa) * 100 : 0.0;
            $optDist[$ph] = [
                'va'                  => round($optVa[$ph], 2),
                'current_a'           => round($optVa[$ph] / self::VOLT, 2),
                'percentage_of_total' => round($pct, 1),
            ];
        }
        [$optSt, $optImb] = $this->imbalanceStatus($optVa);

        // Neutral current: phasor sum assuming PF=1 (approximation; PF data not in circuit totals).
        $optN = $this->computeNeutralCurrent([
            'A' => [['va' => $optVa['A'], 'pf' => 1.0]],
            'B' => [['va' => $optVa['B'], 'pf' => 1.0]],
            'C' => [['va' => $optVa['C'], 'pf' => 1.0]],
        ]);

        $actual = $this->actualDistribution($building);

        return [
            'id'     => $building->id,
            'name'   => $building->name,
            'actual' => $actual,
            'optimal' => [
                'distribution'           => $optDist,
                'status'                 => $optSt,
                'imbalance_percentage'   => $optImb,
                'neutral_current_a'      => round($optN, 2),
                'neutral_current_method' => 'phasor_sum_with_pf',
            ],
            'block_assignments' => $blockAssign,
            'floors'            => $floorsOut,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Compute the complex current phasor for one phase.
     *
     * Each load on the phase has a magnitude (|I| = VA/V) and an
     * angle (θ = θ_V - arccos(PF)). The phase total is the complex
     * sum of all load phasors.
     *
     * This replaces the previous PF=1 approximation:
     *   I_N = sqrt(I_A² + I_B² + I_C² - I_A·I_B - I_B·I_C - I_C·I_A)
     *
     * which assumed θ_I = θ_V for every load (i.e. purely resistive).
     * The new formulation correctly handles inductive loads where
     * current lags voltage by arccos(PF).
     */
    private function computePhaseCurrent(array $loads, float $phaseAngle): array
    {
        $real = 0.0;
        $imag = 0.0;
        foreach ($loads as $load) {
            $va        = (float) $load['va'];
            $pf        = max(0.01, min(1.0, (float) $load['pf']));
            $magnitude = $va / self::VOLT;       // |I| = S/V
            $lag       = acos($pf);              // φ = arccos(PF)
            $angle     = $phaseAngle - $lag;     // θ_I = θ_V - φ
            $real     += $magnitude * cos($angle);
            $imag     += $magnitude * sin($angle);
        }
        return [
            'real'      => $real,
            'imag'      => $imag,
            'magnitude' => sqrt($real ** 2 + $imag ** 2),
        ];
    }

    /** Phasor-sum neutral current for three phases given per-phase load lists. */
    private function computeNeutralCurrent(array $phaseLoads): float
    {
        $iA   = $this->computePhaseCurrent($phaseLoads['A'] ?? [], self::PHASE_ANGLE_A);
        $iB   = $this->computePhaseCurrent($phaseLoads['B'] ?? [], self::PHASE_ANGLE_B);
        $iC   = $this->computePhaseCurrent($phaseLoads['C'] ?? [], self::PHASE_ANGLE_C);
        $real = $iA['real'] + $iB['real'] + $iC['real'];
        $imag = $iA['imag'] + $iB['imag'] + $iC['imag'];
        return sqrt($real ** 2 + $imag ** 2);
    }

    /** 1-phase loads [{name, va, pf, phase, circuit_type}] with group-max dedup. */
    private function extract1ph($components): array
    {
        $groups    = [];
        $ungrouped = [];

        foreach ($components as $c) {
            if ($c->phases === '3phase') continue;
            $va   = (float) $c->power * (int) $c->quantity;
            $pf   = max(0.01, min(1.0, (float) ($c->power_factor ?? 1.0)));
            $item = [
                'name'         => $c->componentType->name ?? 'Component',
                'va'           => $va,
                'pf'           => $pf,
                'phase'        => $c->phase,
                'circuit_type' => $this->classifyComponent($c),
            ];

            if (! $c->group_name) {
                $ungrouped[] = $item;
            } else {
                if (! isset($groups[$c->group_name]) || $va > $groups[$c->group_name]['va']) {
                    $groups[$c->group_name] = $item;
                }
            }
        }

        return array_merge($ungrouped, array_values($groups));
    }

    /**
     * Maps a 1-phase component to the circuit type its load would be packed into
     * by ElectricalDesignService, so room-split sections align with panel circuits.
     * Mirrors the SOCKET → LIGHTING → AUXILIARY priority order in formatLoad().
     */
    private function classifyComponent(object $c): string
    {
        if ($c->needs_socket ?? false) return 'SOCKET';
        $name = strtolower($c->componentType->name ?? '');
        $lightKeywords = [
            'light', 'lamp', 'led strip', 'chandelier', 'luminaire',
            'lantern', 'sconce', 'bulb', 'pendant', 'downlight',
            'spotlight', 'fluorescent', 'fixture',
        ];
        foreach ($lightKeywords as $kw) {
            if (str_contains($name, $kw)) return 'LIGHTING';
        }
        return 'AUXILIARY';
    }

    /**
     * Returns all 1-phase component IDs in a component collection, grouped by
     * circuit type.  Used to build targeted DB update lists for split sections.
     * Unlike extract1ph(), this does NOT deduplicate by group_name — every
     * component in the section must get its phase updated.
     */
    private function getComponentIdsByType($components): array
    {
        $byType = ['SOCKET' => [], 'LIGHTING' => [], 'AUXILIARY' => []];
        foreach ($components as $c) {
            if ($c->phases === '3phase') continue;
            $t = $this->classifyComponent($c);
            $byType[$t][] = $c->id;
        }
        return $byType;
    }

    /**
     * Splits a room's 1-phase load list into N sections by grouping circuit types.
     * Circuit type groups (SOCKET, LIGHTING, AUXILIARY) are assigned to sections
     * greedily (smallest-VA section first) so sections have similar VA totals.
     * This mirrors how ElectricalDesignService creates separate circuit types,
     * ensuring each section maps cleanly to identifiable circuits in the panel.
     *
     * Returns array of sections, each with ['loads' => [...], 'va' => float,
     * 'circuit_types' => string[]].
     */
    private function splitRoomBySections(array $loads, int $n): array
    {
        // Aggregate loads by circuit type
        $byType = [];
        foreach ($loads as $load) {
            $t = $load['circuit_type'] ?? 'AUXILIARY';
            if (! isset($byType[$t])) {
                $byType[$t] = ['loads' => [], 'va' => 0.0];
            }
            $byType[$t]['loads'][] = $load;
            $byType[$t]['va']     += $load['va'];
        }

        // Sort circuit-type groups by VA descending so large groups are placed first
        uasort($byType, fn($a, $b) => $b['va'] <=> $a['va']);

        // Initialise N empty sections
        $sections = array_fill(0, $n, ['loads' => [], 'va' => 0.0, 'circuit_types' => []]);

        // Greedy: assign each circuit-type group to the section with the least VA
        foreach ($byType as $typeName => $typeData) {
            $minVa  = PHP_FLOAT_MAX;
            $minIdx = 0;
            foreach ($sections as $k => $s) {
                if ($s['va'] < $minVa) { $minVa = $s['va']; $minIdx = $k; }
            }
            $sections[$minIdx]['loads']         = array_merge($sections[$minIdx]['loads'], $typeData['loads']);
            $sections[$minIdx]['va']           += $typeData['va'];
            $sections[$minIdx]['circuit_types'][] = $typeName;
        }

        return array_values(array_filter($sections, fn($s) => ! empty($s['loads'])));
    }

    /** Consensus saved phase across 1-phase components (A|B|C|mixed|null). */
    private function consensusPhase($components): ?string
    {
        $seen = [];
        foreach ($components as $c) {
            if ($c->phases === '1phase' && $c->phase !== null) {
                $seen[$c->phase] = true;
            }
        }
        if (empty($seen)) return null;
        $keys = array_keys($seen);
        return count($keys) === 1 ? $keys[0] : 'mixed';
    }

    /** Actual distribution built from saved phase fields. */
    private function actualDistribution(Building $building): array
    {
        $va         = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $phaseLoads = ['A' => [],  'B' => [],   'C' => []];
        $unassigned = 0.0;

        $tally = function ($components) use (&$va, &$phaseLoads, &$unassigned) {
            foreach ($this->extract1ph($components) as $item) {
                if ($item['phase'] && isset($va[$item['phase']])) {
                    $ph = $item['phase'];
                    $va[$ph] += $item['va'];
                    $phaseLoads[$ph][] = ['va' => $item['va'], 'pf' => $item['pf']];
                } else {
                    $unassigned += $item['va'];
                }
            }
        };

        $tally($building->components);
        foreach ($building->getRelation('floors') as $floor) {
            $tally($floor->components);
            foreach ($floor->rooms as $room) {
                $tally($room->components);
            }
        }

        $total = array_sum($va) + $unassigned;

        $phasorCurrents = [
            'A' => $this->computePhaseCurrent($phaseLoads['A'], self::PHASE_ANGLE_A)['magnitude'],
            'B' => $this->computePhaseCurrent($phaseLoads['B'], self::PHASE_ANGLE_B)['magnitude'],
            'C' => $this->computePhaseCurrent($phaseLoads['C'], self::PHASE_ANGLE_C)['magnitude'],
        ];

        $dist = [];
        foreach (['A', 'B', 'C'] as $ph) {
            $pct = $total > 0 ? ($va[$ph] / $total) * 100 : 0.0;
            $dist[$ph] = [
                'va'                  => round($va[$ph], 2),
                'current_a'           => round($phasorCurrents[$ph], 2),
                'percentage_of_total' => round($pct, 1),
            ];
        }

        [$status, $imbalance] = $this->imbalanceStatus($va);
        $neutral = $this->computeNeutralCurrent($phaseLoads);
        if ($unassigned > 0) $status = 'unassigned';

        return [
            'distribution'           => $dist,
            'unassigned_va'          => round($unassigned, 2),
            'status'                 => $status,
            'imbalance_percentage'   => $imbalance,
            'neutral_current_a'      => round($neutral, 2),
            'neutral_current_method' => 'phasor_sum_with_pf',
        ];
    }

    /**
     * Per-phase VA distribution table.
     * $phasorCurrents: optional pre-computed |I_phase| magnitudes from computePhaseCurrent().
     * When omitted, falls back to VA/VOLT (PF=1 approximation).
     */
    private function phaseDistribution(array $va, float $total, array $phasorCurrents = []): array
    {
        $dist = [];
        foreach (['A', 'B', 'C'] as $ph) {
            $pct      = $total > 0 ? ($va[$ph] / $total) * 100 : 0.0;
            $currentA = isset($phasorCurrents[$ph]) ? $phasorCurrents[$ph] : $va[$ph] / self::VOLT;
            $dist[$ph] = [
                'va'                  => round($va[$ph], 2),
                'current_a'           => round($currentA, 2),
                'percentage_of_total' => round($pct, 1),
            ];
        }
        return $dist;
    }

    /**
     * Returns [status, imbalance_pct].
     * Neutral current is computed separately via computeNeutralCurrent() using full phasor math.
     * The imbalance percentage formula is unchanged (max-min)/avg — uses VA/VOLT magnitudes.
     */
    private function imbalanceStatus(array $va): array
    {
        $iA  = $va['A'] / self::VOLT;
        $iB  = $va['B'] / self::VOLT;
        $iC  = $va['C'] / self::VOLT;
        $avg = ($iA + $iB + $iC) / 3;
        $imb = $avg > 0 ? ((max($iA, $iB, $iC) - min($iA, $iB, $iC)) / $avg) * 100 : 0.0;

        $status = match (true) {
            $imb < self::WARN_PCT  => 'balanced',
            $imb <= self::CRIT_PCT => 'warning',
            default                => 'critical',
        };

        return [$status, round($imb, 1)];
    }

    // ── Simple floor-level greedy (used by floor() endpoint) ─────────────────

    private function extractSimple($components, array &$loads1ph, float &$va3ph, int &$count3ph): void
    {
        $groups    = [];
        $ungrouped = [];

        foreach ($components as $c) {
            $va   = (float) $c->power * (int) $c->quantity;
            $pf   = max(0.01, min(1.0, (float) ($c->power_factor ?? 1.0)));
            $name = $c->componentType->name ?? 'Component';

            if (! $c->group_name) {
                $ungrouped[] = ['va' => $va, 'pf' => $pf, 'phases' => $c->phases, 'name' => $name];
            } else {
                $key = $c->group_name;
                if (! isset($groups[$key]) || $va > $groups[$key]['va']) {
                    $groups[$key] = ['va' => $va, 'pf' => $pf, 'phases' => $c->phases, 'name' => $name];
                }
            }
        }

        foreach (array_merge($ungrouped, array_values($groups)) as $item) {
            if ($item['phases'] === '3phase') {
                $va3ph    += $item['va'];
                $count3ph++;
            } else {
                $loads1ph[] = ['name' => $item['name'], 'va' => $item['va'],
                               'pf' => $item['pf'], 'type' => 'component'];
            }
        }
    }

    private function simpleResponse(
        string $entityType, int $entityId, string $entityName,
        array $loads1ph, float $va3ph, int $count3ph
    ): JsonResponse {
        $total1phVa = array_sum(array_column($loads1ph, 'va'));

        $emptyDist = [
            'A' => ['va' => 0.0, 'current_a' => 0.0, 'percentage_of_total' => 0.0],
            'B' => ['va' => 0.0, 'current_a' => 0.0, 'percentage_of_total' => 0.0],
            'C' => ['va' => 0.0, 'current_a' => 0.0, 'percentage_of_total' => 0.0],
        ];

        if (empty($loads1ph)) {
            return response()->json([
                'entity_type'          => $entityType,
                'entity_id'            => $entityId,
                'entity_name'          => $entityName,
                'load_summary'         => ['single_phase_count' => 0, 'three_phase_count' => $count3ph,
                                           'total_single_phase_va' => 0.0, 'total_three_phase_va' => round($va3ph, 2)],
                'phase_distribution'   => $emptyDist,
                'neutral_current_a'    => 0.0,
                'neutral_current_method' => 'phasor_sum_with_pf',
                'imbalance_percentage' => 0.0,
                'status'               => 'balanced',
                'recommendation'       => 'No single-phase loads found at this level.',
                'note'                 => 'Phase assignments are simulated using a greedy balancing algorithm.',
            ]);
        }

        usort($loads1ph, fn($a, $b) => $b['va'] <=> $a['va']);

        $phases      = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $phaseLoads  = ['A' => [],  'B' => [],   'C' => []];
        $largestPhase = null;

        foreach ($loads1ph as $i => $load) {
            $minPhase           = array_keys($phases, min($phases))[0];
            $phases[$minPhase] += $load['va'];
            $phaseLoads[$minPhase][] = ['va' => $load['va'], 'pf' => $load['pf'] ?? 1.0];
            if ($i === 0) $largestPhase = $minPhase;
        }

        [$status, $imbalance] = $this->imbalanceStatus($phases);
        $iN = $this->computeNeutralCurrent($phaseLoads);

        $phasorCurrents = [
            'A' => $this->computePhaseCurrent($phaseLoads['A'], self::PHASE_ANGLE_A)['magnitude'],
            'B' => $this->computePhaseCurrent($phaseLoads['B'], self::PHASE_ANGLE_B)['magnitude'],
            'C' => $this->computePhaseCurrent($phaseLoads['C'], self::PHASE_ANGLE_C)['magnitude'],
        ];
        $phaseDist = $this->phaseDistribution($phases, $total1phVa, $phasorCurrents);

        $heavy = array_keys($phases, max($phases))[0];
        $light = array_keys($phases, min($phases))[0];
        $iNRounded = round($iN, 2);
        $recommendation = match ($status) {
            'balanced' => "Phase distribution is within acceptable limits (imbalance: {$imbalance}%). No rebalancing required.",
            'warning'  => "Phase imbalance is {$imbalance}%. Phase {$heavy} carries " . round($phases[$heavy], 2)
                          . " VA while Phase {$light} carries " . round($phases[$light], 2)
                          . " VA. Redistribute loads to reduce neutral current of {$iNRounded} A.",
            default    => "Critical phase imbalance of {$imbalance}%. Phase {$heavy} carries " . round($phases[$heavy], 2)
                          . " VA versus " . round($phases[$light], 2) . " VA on Phase {$light}. "
                          . "Immediate rebalancing required — neutral current of {$iNRounded} A exceeds safe limits.",
        };

        return response()->json([
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'entity_name'  => $entityName,
            'load_summary' => [
                'single_phase_count'    => count($loads1ph),
                'three_phase_count'     => $count3ph,
                'total_single_phase_va' => round($total1phVa, 2),
                'total_three_phase_va'  => round($va3ph, 2),
            ],
            'phase_distribution'     => $phaseDist,
            'neutral_current_a'      => $iNRounded,
            'neutral_current_method' => 'phasor_sum_with_pf',
            'imbalance_percentage'   => $imbalance,
            'status'                 => $status,
            'status_thresholds'      => ['balanced' => 'imbalance < 10%', 'warning' => '10% to 20%', 'critical' => '> 20%'],
            'largest_single_phase_load' => [
                'name'           => $loads1ph[0]['name'],
                'va'             => round($loads1ph[0]['va'], 2),
                'assigned_phase' => $largestPhase,
            ],
            'recommendation' => $recommendation,
            'note'           => 'Phase assignments are simulated using a greedy balancing algorithm.',
        ]);
    }
}
