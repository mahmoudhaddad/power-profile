<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Project;
use App\Models\Room;
use App\Services\SocketDemandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhaseBalanceController extends Controller
{
    private const VOLT     = 230;
    private const WARN_PCT = 10;
    private const CRIT_PCT = 20;

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

        return response()->json([
            'buildings' => $buildings->map(fn($b) => $this->buildingReport($b, $sockets))->values(),
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

        return response()->json($this->buildingReport($building, new SocketDemandService()));
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
            $loads1ph[] = ['name' => 'Sockets', 'va' => (float) $sd['demand_va'], 'type' => 'socket'];
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

        $report = $this->buildingReport($building, new SocketDemandService());

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
            // 'socket' blocks are not DB records — skip
        }

        return response()->json(['message' => 'Optimal phase assignment applied.']);
    }

    // ── Core building report ──────────────────────────────────────────────────

    private function buildingReport(Building $building, SocketDemandService $sockets): array
    {
        $blocks    = [];  // VA blocks used for greedy
        $floorsOut = [];

        // Building-level own 1ph components
        $bOwn = $this->extract1ph($building->components);
        $bVa  = array_sum(array_column($bOwn, 'va'));
        if ($bVa > 0) {
            $blocks[] = ['type' => 'building', 'name' => 'Building components',
                         'va' => $bVa, 'floor_id' => null, 'room_id' => null];
        }

        foreach ($building->getRelation('floors') as $floor) {
            // Floor own
            $fOwn = $this->extract1ph($floor->components);
            $fVa  = array_sum(array_column($fOwn, 'va'));
            if ($fVa > 0) {
                $blocks[] = ['type' => 'floor_own', 'name' => 'Floor components – ' . $floor->name,
                             'va' => $fVa, 'floor_id' => $floor->id, 'room_id' => null];
            }

            // Socket demand for the floor panel
            $sd = $sockets->floorResult($floor);
            if ($sd['demand_va'] > 0) {
                $blocks[] = ['type' => 'socket', 'name' => 'Sockets – ' . $floor->name,
                             'va' => (float) $sd['demand_va'], 'floor_id' => $floor->id, 'room_id' => null];
            }

            // Rooms
            $roomsOut = [];
            foreach ($floor->rooms as $room) {
                $r1ph = $this->extract1ph($room->components);
                $rVa  = array_sum(array_column($r1ph, 'va'));

                $roomsOut[] = [
                    'id'            => $room->id,
                    'name'          => $room->name,
                    'va_1ph'        => round($rVa, 2),
                    'actual_phase'  => $this->consensusPhase($room->components),
                    'optimal_phase' => null,  // filled after greedy
                ];

                if ($rVa > 0) {
                    $blocks[] = ['type' => 'room', 'name' => $room->name . ' (' . $floor->name . ')',
                                 'va' => $rVa, 'floor_id' => $floor->id, 'room_id' => $room->id];
                }
            }

            $floorsOut[] = ['id' => $floor->id, 'name' => $floor->name, 'rooms' => $roomsOut];
        }

        // ── Greedy on blocks (largest first) ──────────────────────────────────
        usort($blocks, fn($a, $b) => $b['va'] <=> $a['va']);
        $optVa       = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $blockAssign = [];
        foreach ($blocks as $blk) {
            $ph = array_keys($optVa, min($optVa))[0];
            $optVa[$ph] += $blk['va'];
            $blockAssign[] = $blk + ['optimal_phase' => $ph];
        }

        // Fill optimal_phase back into floor rooms
        foreach ($floorsOut as &$floorOut) {
            foreach ($floorOut['rooms'] as &$roomOut) {
                foreach ($blockAssign as $ba) {
                    if ($ba['type'] === 'room' && $ba['room_id'] === $roomOut['id']) {
                        $roomOut['optimal_phase'] = $ba['optimal_phase'];
                        break;
                    }
                }
            }
        }
        unset($floorOut, $roomOut);

        // ── Distributions ─────────────────────────────────────────────────────
        $actual      = $this->actualDistribution($building);
        $total1phVa  = array_sum(array_column($blocks, 'va'));
        $optDist     = $this->phaseDistribution($optVa, $total1phVa);
        [$optSt, $optImb, $optN] = $this->imbalanceStatus($optVa);

        return [
            'id'     => $building->id,
            'name'   => $building->name,
            'actual' => $actual,
            'optimal' => [
                'distribution'         => $optDist,
                'status'               => $optSt,
                'imbalance_percentage' => $optImb,
                'neutral_current_a'    => $optN,
            ],
            'block_assignments' => $blockAssign,
            'floors'            => $floorsOut,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** 1-phase loads [{name, va, phase}] with group-max dedup. */
    private function extract1ph($components): array
    {
        $groups    = [];
        $ungrouped = [];

        foreach ($components as $c) {
            if ($c->phases === '3phase') continue;
            $va   = (float) $c->power * (int) $c->quantity;
            $item = ['name' => $c->componentType->name ?? 'Component', 'va' => $va, 'phase' => $c->phase];

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
        $unassigned = 0.0;

        $tally = function ($components) use (&$va, &$unassigned) {
            foreach ($this->extract1ph($components) as $item) {
                if ($item['phase'] && isset($va[$item['phase']])) {
                    $va[$item['phase']] += $item['va'];
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
        $dist  = [];
        foreach (['A', 'B', 'C'] as $ph) {
            $pct = $total > 0 ? ($va[$ph] / $total) * 100 : 0.0;
            $dist[$ph] = [
                'va'                  => round($va[$ph], 2),
                'current_a'           => round($va[$ph] / self::VOLT, 2),
                'percentage_of_total' => round($pct, 1),
            ];
        }

        [$status, $imbalance, $neutral] = $this->imbalanceStatus($va);
        if ($unassigned > 0) $status = 'unassigned';

        return [
            'distribution'         => $dist,
            'unassigned_va'        => round($unassigned, 2),
            'status'               => $status,
            'imbalance_percentage' => $imbalance,
            'neutral_current_a'    => $neutral,
        ];
    }

    private function phaseDistribution(array $va, float $total): array
    {
        $dist = [];
        foreach (['A', 'B', 'C'] as $ph) {
            $pct = $total > 0 ? ($va[$ph] / $total) * 100 : 0.0;
            $dist[$ph] = [
                'va'                  => round($va[$ph], 2),
                'current_a'           => round($va[$ph] / self::VOLT, 2),
                'percentage_of_total' => round($pct, 1),
            ];
        }
        return $dist;
    }

    private function imbalanceStatus(array $va): array
    {
        $iA = $va['A'] / self::VOLT;
        $iB = $va['B'] / self::VOLT;
        $iC = $va['C'] / self::VOLT;

        $iN  = sqrt(max(0.0, $iA ** 2 + $iB ** 2 + $iC ** 2 - $iA * $iB - $iB * $iC - $iC * $iA));
        $avg = ($iA + $iB + $iC) / 3;
        $imb = $avg > 0 ? ((max($iA, $iB, $iC) - min($iA, $iB, $iC)) / $avg) * 100 : 0.0;

        $status = match (true) {
            $imb < self::WARN_PCT  => 'balanced',
            $imb <= self::CRIT_PCT => 'warning',
            default                => 'critical',
        };

        return [$status, round($imb, 1), round($iN, 2)];
    }

    // ── Simple floor-level greedy (used by floor() endpoint) ─────────────────

    private function extractSimple($components, array &$loads1ph, float &$va3ph, int &$count3ph): void
    {
        $groups    = [];
        $ungrouped = [];

        foreach ($components as $c) {
            $va   = (float) $c->power * (int) $c->quantity;
            $name = $c->componentType->name ?? 'Component';

            if (! $c->group_name) {
                $ungrouped[] = ['va' => $va, 'phases' => $c->phases, 'name' => $name];
            } else {
                $key = $c->group_name;
                if (! isset($groups[$key]) || $va > $groups[$key]['va']) {
                    $groups[$key] = ['va' => $va, 'phases' => $c->phases, 'name' => $name];
                }
            }
        }

        foreach (array_merge($ungrouped, array_values($groups)) as $item) {
            if ($item['phases'] === '3phase') {
                $va3ph    += $item['va'];
                $count3ph++;
            } else {
                $loads1ph[] = ['name' => $item['name'], 'va' => $item['va'], 'type' => 'component'];
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
                'imbalance_percentage' => 0.0,
                'status'               => 'balanced',
                'recommendation'       => 'No single-phase loads found at this level.',
                'note'                 => 'Phase assignments are simulated using a greedy balancing algorithm.',
            ]);
        }

        usort($loads1ph, fn($a, $b) => $b['va'] <=> $a['va']);
        $phases       = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $largestPhase = null;

        foreach ($loads1ph as $i => $load) {
            $minPhase          = array_keys($phases, min($phases))[0];
            $phases[$minPhase] += $load['va'];
            if ($i === 0) $largestPhase = $minPhase;
        }

        [$status, $imbalance, $iN] = $this->imbalanceStatus($phases);

        $heavy = array_keys($phases, max($phases))[0];
        $light = array_keys($phases, min($phases))[0];
        $recommendation = match ($status) {
            'balanced' => "Phase distribution is within acceptable limits (imbalance: {$imbalance}%). No rebalancing required.",
            'warning'  => "Phase imbalance is {$imbalance}%. Phase {$heavy} carries " . round($phases[$heavy], 2)
                          . " VA while Phase {$light} carries " . round($phases[$light], 2)
                          . " VA. Redistribute loads to reduce neutral current of {$iN} A.",
            default    => "Critical phase imbalance of {$imbalance}%. Phase {$heavy} carries " . round($phases[$heavy], 2)
                          . " VA versus " . round($phases[$light], 2) . " VA on Phase {$light}. "
                          . "Immediate rebalancing required — neutral current of {$iN} A exceeds safe limits.",
        };

        $phaseDist = $this->phaseDistribution($phases, $total1phVa);

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
            'phase_distribution'    => $phaseDist,
            'neutral_current_a'     => $iN,
            'imbalance_percentage'  => $imbalance,
            'status'                => $status,
            'status_thresholds'     => ['balanced' => 'imbalance < 10%', 'warning' => '10% to 20%', 'critical' => '> 20%'],
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
