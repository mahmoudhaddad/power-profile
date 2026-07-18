<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\PhaseBalanceController;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Services\ElectricalDesignService;
use App\Services\SocketDemandService;
use Tests\TestCase;

/**
 * Verifies that PhaseBalanceController derives its "optimal" distribution
 * from ElectricalDesignService circuit-level output so both pages show
 * identical per-phase VA totals and imbalance %.
 *
 * Tests are database-free: we inject a mock ElectricalDesignService and use
 * Eloquent's newFromBuilder() to hydrate model instances without DB queries.
 */
class PhaseBalanceConsistencyTest extends TestCase
{
    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Call a private method via reflection. */
    private function invoke(object $obj, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($obj, $method))->invoke($obj, ...$args);
    }

    /**
     * Build a minimal ElectricalDesignService mock whose analyzeProject()
     * returns the supplied $edResult fixture.
     */
    private function mockEdService(array $edResult): ElectricalDesignService
    {
        $mock = $this->createMock(ElectricalDesignService::class);
        $mock->method('analyzeProject')->willReturn($edResult);
        return $mock;
    }

    /**
     * Build a PhaseBalanceController with a mock ED service and call the
     * private buildingReport() directly.
     *
     * $building must be a real Building model instance so PHP accepts the type hint.
     * $edBuilding is the matching entry from $edResult['buildings'].
     */
    private function report(
        Building $building,
        array $edResult,
        ?array $edBuilding = null
    ): array {
        $ctrl    = new PhaseBalanceController($this->mockEdService($edResult));
        $sockets = $this->createMock(SocketDemandService::class);
        return (new \ReflectionMethod($ctrl, 'buildingReport'))
            ->invoke($ctrl, $building, $sockets, $edBuilding ?? $edResult['buildings'][0]);
    }

    /**
     * Compute the ED-style imbalance % directly so tests can assert
     * Phase Balance matches the same formula.
     * formula: (max − min) / avg × 100
     */
    private function edImbalance(array $phaseVa): float
    {
        $vals = array_values($phaseVa);
        $avg  = array_sum($vals) / 3.0;
        return $avg > 0 ? round((max($vals) - min($vals)) / $avg * 100, 1) : 0.0;
    }

    /**
     * Build a minimal ED result with one building / one floor.
     * $circuits = array of circuit arrays with 'is3ph', 'phase', 'total_va', 'room_names'.
     */
    private function edResult(
        array $circuits,
        int $buildingId = 1,
        int $floorId    = 1
    ): array {
        $va = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        foreach ($circuits as $c) {
            if ($c['is3ph'] ?? false) continue;
            $ph = $c['phase'] ?? null;
            if ($ph && isset($va[$ph])) {
                $va[$ph] += (float) ($c['total_va'] ?? 0);
            }
        }

        return [
            'buildings' => [[
                'id'     => $buildingId,
                'name'   => 'Test Building',
                'floors' => [[
                    'id'       => $floorId,
                    'name'     => 'Floor 1',
                    'db'       => [
                        'phase_balance_va'    => array_map(fn($v) => round($v, 1), $va),
                        'phase_imbalance_pct' => $this->edImbalance($va),
                    ],
                    'circuits' => $circuits,
                ]],
            ]],
        ];
    }

    /**
     * Build a real Building model wired with one floor and the given rooms.
     *
     * $rooms = [['name' => ..., 'components' => [ component-def... ]], ...]
     * component-def = ['type_name', 'power', optional: 'needs_socket', 'phase', 'phases']
     *
     * Relations are set with setRelation() so Eloquent getRelation() works.
     */
    private function building(array $rooms = [], int $buildingId = 1, int $floorId = 1): Building
    {
        $building = (new Building())->newFromBuilder(['id' => $buildingId, 'name' => 'Test Building']);
        $building->setRelation('components', collect([]));

        $roomModels = [];
        foreach ($rooms as $idx => $roomDef) {
            $room = (new Room())->newFromBuilder([
                'id'   => $idx + 1,
                'name' => $roomDef['name'],
                'type' => $roomDef['type'] ?? 'office',
            ]);
            $room->setRelation('components', collect($this->makeComponents($roomDef['components'] ?? [])));
            $roomModels[] = $room;
        }

        $floor = (new Floor())->newFromBuilder(['id' => $floorId, 'name' => 'Floor 1']);
        $floor->setRelation('components', collect([]));
        $floor->setRelation('rooms', collect($roomModels));

        $building->setRelation('floors', collect([$floor]));

        return $building;
    }

    /** Create minimal Component-like stdClass objects (no Eloquent needed here). */
    private function makeComponents(array $defs): array
    {
        $comps = [];
        foreach ($defs as $i => $def) {
            $ct           = new \stdClass();
            $ct->name     = $def['type_name'] ?? 'Device';
            $ct->is_motor = false;

            $c                = new \stdClass();
            $c->id            = $i + 100;
            $c->phases        = $def['phases'] ?? '1phase';
            $c->phase         = $def['phase']  ?? null;
            $c->power         = (float) ($def['power'] ?? 100.0);
            $c->quantity      = 1;
            $c->power_factor  = 1.0;
            $c->group_name    = null;
            $c->priority      = 'normal';
            $c->needs_socket  = $def['needs_socket'] ?? false;
            $c->componentType = $ct;
            $comps[]          = $c;
        }
        return $comps;
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * The "optimal" per-phase VA totals must equal ED floor phase_balance_va.
     * This is the fundamental consistency guarantee.
     */
    public function test_optimal_va_totals_equal_ed_floor_phase_balance_va(): void
    {
        $circuits = [
            ['is3ph' => false, 'type' => 'LIGHTING', 'phase' => 'A', 'total_va' => 562.0, 'room_names' => ['R']],
            ['is3ph' => false, 'type' => 'SOCKET',   'phase' => 'B', 'total_va' => 504.0, 'room_names' => ['R']],
            ['is3ph' => false, 'type' => 'AUXILIARY', 'phase' => 'C', 'total_va' => 482.0, 'room_names' => ['R']],
        ];

        $ed      = $this->edResult($circuits);
        $bldg    = $this->building([['name' => 'R', 'components' => [
            ['type_name' => 'Light', 'power' => 100],
        ]]]);
        $report  = $this->report($bldg, $ed);
        $optDist = $report['optimal']['distribution'];

        $this->assertEqualsWithDelta(562.0, $optDist['A']['va'], 0.1, 'A VA must match ED');
        $this->assertEqualsWithDelta(504.0, $optDist['B']['va'], 0.1, 'B VA must match ED');
        $this->assertEqualsWithDelta(482.0, $optDist['C']['va'], 0.1, 'C VA must match ED');
    }

    /**
     * The "optimal" imbalance % must equal the value derived from ED floor phase_balance_va.
     */
    public function test_optimal_imbalance_pct_equals_ed_derived_value(): void
    {
        $phaseVa = ['A' => 562.0, 'B' => 504.0, 'C' => 482.0];
        $circuits = [
            ['is3ph' => false, 'type' => 'LIGHTING',  'phase' => 'A', 'total_va' => $phaseVa['A'], 'room_names' => []],
            ['is3ph' => false, 'type' => 'SOCKET',    'phase' => 'B', 'total_va' => $phaseVa['B'], 'room_names' => []],
            ['is3ph' => false, 'type' => 'AUXILIARY', 'phase' => 'C', 'total_va' => $phaseVa['C'], 'room_names' => []],
        ];

        $ed     = $this->edResult($circuits);
        $bldg   = $this->building();
        $report = $this->report($bldg, $ed);

        $this->assertEqualsWithDelta(
            $this->edImbalance($phaseVa),
            $report['optimal']['imbalance_percentage'],
            0.1,
            'imbalance_percentage must equal ED floor value'
        );
    }

    /**
     * A room whose circuits land on multiple phases must have is_split = true
     * and a split_sections list with one entry per phase.
     */
    public function test_room_with_circuits_on_multiple_phases_is_split(): void
    {
        // Studying-hall pattern: LIGHTING on A + B, SOCKET on C
        $circuits = [
            ['is3ph' => false, 'type' => 'LIGHTING', 'phase' => 'A', 'total_va' => 432.0, 'room_names' => ['Study Hall']],
            ['is3ph' => false, 'type' => 'LIGHTING', 'phase' => 'B', 'total_va' => 432.0, 'room_names' => ['Study Hall']],
            ['is3ph' => false, 'type' => 'SOCKET',   'phase' => 'C', 'total_va' => 300.0, 'room_names' => ['Study Hall']],
        ];

        $ed    = $this->edResult($circuits);
        $bldg  = $this->building([['name' => 'Study Hall', 'components' => [
            ['type_name' => 'Light',  'power' => 864.0],
            ['type_name' => 'Socket', 'power' => 300.0, 'needs_socket' => true],
        ]]]);
        $report = $this->report($bldg, $ed);
        $room   = $report['floors'][0]['rooms'][0];

        $this->assertTrue($room['is_split'], 'Room with circuits on A, B, C must be marked split');
        $this->assertCount(3, $room['split_sections'], 'Three phases → three split sections');
    }

    /**
     * Studying-hall split sections must list phases A, B, C
     * with approximately correct VA values derived from circuit proportions.
     */
    public function test_studying_hall_split_sections_match_circuit_phase_va(): void
    {
        $circuits = [
            ['is3ph' => false, 'type' => 'LIGHTING', 'phase' => 'A', 'total_va' => 432.0, 'room_names' => ['Study Hall']],
            ['is3ph' => false, 'type' => 'LIGHTING', 'phase' => 'B', 'total_va' => 432.0, 'room_names' => ['Study Hall']],
            ['is3ph' => false, 'type' => 'SOCKET',   'phase' => 'C', 'total_va' => 300.0, 'room_names' => ['Study Hall']],
        ];

        $ed    = $this->edResult($circuits);
        $bldg  = $this->building([['name' => 'Study Hall', 'components' => [
            ['type_name' => 'Light',  'power' => 864.0],
            ['type_name' => 'Socket', 'power' => 300.0, 'needs_socket' => true],
        ]]]);
        $report = $this->report($bldg, $ed);
        $sects  = collect($report['floors'][0]['rooms'][0]['split_sections'])->keyBy('phase');

        $this->assertArrayHasKey('A', $sects);
        $this->assertArrayHasKey('B', $sects);
        $this->assertArrayHasKey('C', $sects);

        // Lighting 864 VA splits 50/50 onto A and B; socket 300 VA → C
        $this->assertEqualsWithDelta(432.0, $sects['A']['va'], 1.0, 'Phase A ≈ 432 VA');
        $this->assertEqualsWithDelta(432.0, $sects['B']['va'], 1.0, 'Phase B ≈ 432 VA');
        $this->assertEqualsWithDelta(300.0, $sects['C']['va'], 1.0, 'Phase C ≈ 300 VA');
    }

    /**
     * A room whose circuits all land on the same phase must NOT be split.
     */
    public function test_room_with_all_circuits_on_one_phase_is_not_split(): void
    {
        $circuits = [
            ['is3ph' => false, 'type' => 'LIGHTING', 'phase' => 'A', 'total_va' => 300.0, 'room_names' => ['Office']],
            ['is3ph' => false, 'type' => 'SOCKET',   'phase' => 'A', 'total_va' => 200.0, 'room_names' => ['Office']],
        ];

        $ed    = $this->edResult($circuits);
        $bldg  = $this->building([['name' => 'Office', 'components' => [
            ['type_name' => 'Light',  'power' => 300.0],
            ['type_name' => 'Socket', 'power' => 200.0, 'needs_socket' => true],
        ]]]);
        $report = $this->report($bldg, $ed);
        $room   = $report['floors'][0]['rooms'][0];

        $this->assertFalse($room['is_split'], 'All circuits on A → not split');
        $this->assertEquals('A', $room['optimal_phase'], 'optimal_phase must be A');
        $this->assertEmpty($room['split_sections']);
    }

    /**
     * Building totals must be the sum of per-floor phase_balance_va values.
     * Tests multi-floor accumulation.
     */
    public function test_building_totals_sum_across_floors(): void
    {
        $f1Va = ['A' => 562.0, 'B' => 504.0, 'C' => 482.0];
        $f2Va = ['A' => 300.0, 'B' => 450.0, 'C' => 350.0];

        $ed = [
            'buildings' => [[
                'id'     => 1,
                'name'   => 'Building',
                'floors' => [
                    ['id' => 1, 'name' => 'Floor 1', 'db' => ['phase_balance_va' => $f1Va, 'phase_imbalance_pct' => 0.0], 'circuits' => []],
                    ['id' => 2, 'name' => 'Floor 2', 'db' => ['phase_balance_va' => $f2Va, 'phase_imbalance_pct' => 0.0], 'circuits' => []],
                ],
            ]],
        ];

        // Building with two empty floors
        $bldg = (new Building())->newFromBuilder(['id' => 1, 'name' => 'Building']);
        $bldg->setRelation('components', collect([]));

        $floor1 = (new Floor())->newFromBuilder(['id' => 1, 'name' => 'Floor 1']);
        $floor1->setRelation('components', collect([]));
        $floor1->setRelation('rooms', collect([]));

        $floor2 = (new Floor())->newFromBuilder(['id' => 2, 'name' => 'Floor 2']);
        $floor2->setRelation('components', collect([]));
        $floor2->setRelation('rooms', collect([]));

        $bldg->setRelation('floors', collect([$floor1, $floor2]));

        $report = $this->report($bldg, $ed);
        $dist   = $report['optimal']['distribution'];

        $this->assertEqualsWithDelta($f1Va['A'] + $f2Va['A'], $dist['A']['va'], 0.1, 'A total across floors');
        $this->assertEqualsWithDelta($f1Va['B'] + $f2Va['B'], $dist['B']['va'], 0.1, 'B total across floors');
        $this->assertEqualsWithDelta($f1Va['C'] + $f2Va['C'], $dist['C']['va'], 0.1, 'C total across floors');
    }

    /**
     * The imbalance formula used by PhaseBalanceController must be identical
     * to the one used by ElectricalDesignService — (max−min)/avg × 100.
     */
    public function test_imbalance_formula_is_identical_between_services(): void
    {
        $ctrl = new PhaseBalanceController($this->createMock(ElectricalDesignService::class));

        $cases = [
            ['A' => 562.0, 'B' => 504.0, 'C' => 482.0],   // 15.5 %
            ['A' => 864.0, 'B' => 342.0, 'C' => 342.0],   // 101.2 %
            ['A' => 500.0, 'B' => 500.0, 'C' => 500.0],   // 0 %
        ];

        foreach ($cases as $va) {
            [, $ctrlImb] = $this->invoke($ctrl, 'imbalanceStatus', $va);
            $this->assertEqualsWithDelta(
                $this->edImbalance($va),
                $ctrlImb,
                0.1,
                'imbalanceStatus must use (max−min)/avg × 100'
            );
        }
    }
}
