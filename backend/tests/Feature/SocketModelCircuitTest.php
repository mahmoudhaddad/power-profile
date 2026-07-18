<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\ComponentType;
use App\Models\Floor;
use App\Models\Project;
use App\Models\Room;
use App\Models\RoomComponent;
use App\Models\User;
use App\Services\ElectricalDesignService;
use App\Services\SocketDemandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 tests — Socket model → real panel circuits.
 *
 * Invariants verified:
 *  1. Every socket-model circuit has a real locatable ID and valid IEC 60364 sizing.
 *  2. socket_circuit_id is consistent between socketCircuitIndex() and analyzeProject().
 *  3. Phase Balance and MDB incomer sizing reflect the previously-invisible socket load.
 *  4. No double-counting: sum of real circuit VA ≤ SocketDemandService connected_va.
 *  5. All socket-model circuits on a floor get a phase assignment.
 */
class SocketModelCircuitTest extends TestCase
{
    use RefreshDatabase;

    private ElectricalDesignService $edSvc;
    private SocketDemandService     $socketSvc;

    /** Minimal project fixture — school, 2 buildings, 1 floor each, 2 rooms each. */
    private function makeProject(bool $withAllocation = false): Project
    {
        $user = User::create([
            'name'     => 'Test',
            'email'    => 'test@example.com',
            'password' => bcrypt('pw'),
        ]);
        $project = Project::create([
            'user_id'       => $user->id,
            'name'          => 'Socket Test',
            'building_type' => 'school',
        ]);

        foreach ([['name' => 'R', 'type' => 'school'], ['name' => 'P', 'type' => 'school']] as $bDef) {
            $bldg  = Building::create(['project_id' => $project->id, 'name' => $bDef['name'], 'type' => $bDef['type']]);
            $floor = Floor::create(['building_id' => $bldg->id, 'name' => 'ground']);

            // Room A — 10 raw socket outlets
            $roomA = Room::create(['floor_id' => $floor->id, 'name' => 'Room A']);
            $roomA->sockets()->create(['quantity' => 10, 'power' => 200]);

            // Room B — 5 raw socket outlets
            $roomB = Room::create(['floor_id' => $floor->id, 'name' => 'Room B']);
            $roomB->sockets()->create(['quantity' => 5, 'power' => 200]);

            // Optional: 2 needs_socket=true RoomComponents on Room A to test allocation deduction
            if ($withAllocation) {
                $ct = ComponentType::firstOrCreate(
                    ['name' => 'PC'],
                    ['is_motor' => false, 'default_power' => 200, 'default_phases' => '1phase', 'default_power_factor' => 1.0]
                );
                RoomComponent::create([
                    'room_id'           => $roomA->id,
                    'component_type_id' => $ct->id,
                    'power'             => 200,
                    'quantity'          => 2,
                    'needs_socket'      => true,
                ]);
            }
        }

        return $project;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->edSvc     = app(ElectricalDesignService::class);
        $this->socketSvc = new SocketDemandService();
    }

    // ── Test 1: real location + real electrical rating ────────────────────────

    public function test_every_socket_model_circuit_has_valid_location_and_sizing(): void
    {
        $project = $this->makeProject();
        $result  = $this->edSvc->analyzeProject($project);

        $smCircuits = [];
        foreach ($result['buildings'] as $bldg) {
            foreach ($bldg['floors'] as $floor) {
                foreach ($floor['circuits'] as $c) {
                    if (($c['socket_source'] ?? null) === 'socket_model') {
                        $smCircuits[] = $c;
                    }
                }
            }
        }

        $this->assertNotEmpty($smCircuits, 'analyzeProject must produce socket-model circuits');

        foreach ($smCircuits as $c) {
            // ID present and correctly formatted: socket/{bldg}/{floor}/SM{n}
            $this->assertNotEmpty($c['socket_circuit_id'] ?? '',
                'socket_circuit_id must be set on every socket-model circuit');
            $this->assertMatchesRegularExpression(
                '#^socket/[^/]+/[^/]+/SM\d+$#',
                $c['socket_circuit_id'],
                "ID format wrong: {$c['socket_circuit_id']}"
            );

            // IEC 60364 §8.5.7: socket circuits ≥ 16 A breaker, ≥ 2.5 mm² cable
            $this->assertGreaterThanOrEqual(16, $c['in_a'],
                "Breaker {$c['in_a']}A < 16A on {$c['socket_circuit_id']}");
            $this->assertGreaterThanOrEqual(2.5, (float) $c['cable_mm2'],
                "Cable {$c['cable_mm2']}mm² < 2.5mm² on {$c['socket_circuit_id']}");

            $this->assertGreaterThan(0.0, (float) $c['total_va'],
                "total_va must be > 0 on {$c['socket_circuit_id']}");
        }
    }

    // ── Test 2: SM numbers consistent between index and analyzeProject ─────────

    public function test_socket_circuit_ids_consistent_between_index_and_panel(): void
    {
        $project = $this->makeProject(withAllocation: true);

        $indexCircuits = $this->edSvc->socketCircuitIndex($project);
        $panelResult   = $this->edSvc->analyzeProject($project);

        // Collect IDs from panel: floor circuits + MDB-level circuits
        $panelIds = [];
        foreach ($panelResult['buildings'] as $bldg) {
            foreach ($bldg['floors'] as $floor) {
                foreach ($floor['circuits'] as $c) {
                    if (($c['socket_source'] ?? null) === 'socket_model') {
                        $panelIds[] = $c['socket_circuit_id'];
                    }
                }
            }
            foreach ($bldg['mdb_circuits'] ?? [] as $c) {
                if (($c['socket_source'] ?? null) === 'socket_model') {
                    $panelIds[] = $c['socket_circuit_id'];
                }
            }
        }

        $indexIds = array_column($indexCircuits, 'socket_circuit_id');

        $this->assertNotEmpty($indexIds, 'socketCircuitIndex must return circuits');

        foreach ($indexIds as $id) {
            $this->assertContains($id, $panelIds,
                "'{$id}' from socketCircuitIndex() missing in analyzeProject() panel");
        }

        $this->assertCount(
            \count($indexIds), $panelIds,
            "Count mismatch: index=" . \count($indexIds) . " panel=" . \count($panelIds)
        );
    }

    // ── Test 3: incomer sizing increases when socket load is visible ───────────

    public function test_incomer_upsizes_when_socket_model_load_is_present(): void
    {
        // Project A — rooms with NO socket model records
        $userA    = User::create(['name' => 'A', 'email' => 'a@test.com', 'password' => 'pw']);
        $projBase = Project::create(['user_id' => $userA->id, 'name' => 'Base', 'building_type' => 'school']);
        $bldgBase = Building::create(['project_id' => $projBase->id, 'name' => 'R', 'type' => 'school']);
        $flrBase  = Floor::create(['building_id' => $bldgBase->id, 'name' => 'ground']);
        Room::create(['floor_id' => $flrBase->id, 'name' => 'Empty Room']);

        // Project B — same layout but with 50 socket outlets across rooms
        $project = $this->makeProject();  // 15 outlets per building, 2 buildings

        $baseResult = $this->edSvc->analyzeProject($projBase);
        $sockResult = $this->edSvc->analyzeProject($project);

        $baseBuilding = $baseResult['buildings'][0];
        $sockBuilding = $sockResult['buildings'][0];  // Building R in socket project

        $this->assertGreaterThan(
            $baseBuilding['mdb']['total_va_nameplate'],
            $sockBuilding['mdb']['total_va_nameplate'],
            'MDB nameplate VA must increase when socket-model outlets are added'
        );

        $this->assertGreaterThanOrEqual(
            $baseBuilding['mdb']['incomer_in_a'],
            $sockBuilding['mdb']['incomer_in_a'],
            'MDB incomer must be ≥ baseline after socket load added'
        );
    }

    // ── Test 4: no double-counting with SocketDemandService ──────────────────

    public function test_no_double_counting_of_socket_demand(): void
    {
        $project     = $this->makeProject(withAllocation: true);
        $socketResult = $this->socketSvc->projectResult($project);
        $connectedVA  = (float) $socketResult['connected_va'];

        $circuits    = $this->edSvc->socketCircuitIndex($project);
        $realTotalVA = (float) array_sum(array_column($circuits, 'total_va'));

        // Both services use integer multiples of OUTLET_VA=200 with an identical
        // needs_socket deduction query — so the sums must agree to within 1 VA
        // (float tolerance only; in practice the delta is always exactly 0).
        $this->assertGreaterThan(0.0, $realTotalVA,
            'socketCircuitIndex() must return circuits with positive VA');

        $this->assertEqualsWithDelta(
            $connectedVA,
            $realTotalVA,
            1.0,
            "Socket circuit VA sum ({$realTotalVA}) must equal SocketDemandService connected_va ({$connectedVA}) — " .
            "a larger gap means some outlets are silently missing from the panel circuits"
        );
    }

    // ── Test 5: phase balance accounts for socket-model load ──────────────────

    public function test_phase_balance_includes_socket_model_circuits(): void
    {
        $project = $this->makeProject();
        $result  = $this->edSvc->analyzeProject($project);

        foreach ($result['buildings'] as $bldg) {
            foreach ($bldg['floors'] as $floor) {
                $hasSM = false;
                foreach ($floor['circuits'] as $c) {
                    if (($c['socket_source'] ?? null) === 'socket_model') {
                        $hasSM = true;
                        $this->assertNotNull($c['phase'],
                            "Socket-model circuit {$c['socket_circuit_id']} has no phase");
                        $this->assertContains($c['phase'], ['A', 'B', 'C'],
                            "Socket-model circuit {$c['socket_circuit_id']} invalid phase '{$c['phase']}'");
                    }
                }
                if ($hasSM) {
                    $totalPhaseVA = array_sum($floor['db']['phase_balance_va']);
                    $this->assertGreaterThan(0.0, $totalPhaseVA,
                        "Floor '{$floor['name']}' has SM circuits but zero phase VA");
                }
            }
        }
    }
}
