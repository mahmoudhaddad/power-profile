<?php

namespace Tests\Feature;

use App\Services\ElectricalDesignService;
use Tests\TestCase;

/**
 * Unit tests for ElectricalDesignService.
 *
 * These tests exercise the sizing and classification logic directly,
 * without hitting the database.
 */
class ElectricalDesignTest extends TestCase
{
    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeService(): ElectricalDesignService
    {
        return new ElectricalDesignService();
    }

    /** Invoke a private method via reflection. */
    private function invoke(object $obj, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($obj, $method))->invoke($obj, ...$args);
    }

    /** Default rules matching ElectricalDesignService::DEFAULT_RULES. */
    private function defaultRules(): array
    {
        return [
            'heavy_threshold_va'           => 2000,
            'motor_dedicated_threshold_va' => 750,
            'socket_outlets_per_circuit'   => 8,
            'lighting_breaker_a'           => 10,
            'socket_breaker_a'             => 16,
            'auxiliary_breaker_a'          => 10,
            'breaker_loading_factor'       => 0.80,
            'spare_capacity_pct'           => 10,
            'rcd_policy'                   => '30mA_socket_lighting',
            'essential_separation'         => false,
            'allow_mixed_general_circuits' => false,
            'group_small_critical'         => false,
            'standard_ref'                 => 'IEC 60364',
        ];
    }

    /** Build a mock component stdClass for formatLoad tests. */
    private function mockComponent(string $typeName, float $power, string $priority = 'normal', bool $needsSocket = false, bool $isMotor = false): \stdClass
    {
        $ct           = new \stdClass();
        $ct->name     = $typeName;
        $ct->is_motor = $isMotor;

        $c                = new \stdClass();
        $c->componentType = $ct;
        $c->power         = $power;
        $c->quantity      = 1;
        $c->phases        = '1phase';
        $c->needs_socket  = $needsSocket;
        $c->priority      = $priority;
        $c->group_name    = null;

        return $c;
    }

    // ── Ib formula ─────────────────────────────────────────────────────────────

    public function test_design_current_1phase_uses_va_not_watts(): void
    {
        $svc = $this->makeService();
        // 2300 VA 1-phase → Ib = 2300 / 230 = 10 A exactly
        $ib = $this->invoke($svc, 'computeIb', 2300.0, false);
        $this->assertEqualsWithDelta(10.0, $ib, 0.01);
    }

    public function test_design_current_3phase(): void
    {
        $svc = $this->makeService();
        // 6928 VA 3-phase → Ib = 6928 / (√3 × 400) ≈ 10.0 A
        $ib = $this->invoke($svc, 'computeIb', 6928.0, true);
        $this->assertEqualsWithDelta(10.0, $ib, 0.1);
    }

    // ── Breaker sizing ─────────────────────────────────────────────────────────

    public function test_next_breaker_size_returns_standard_rating(): void
    {
        $svc = $this->makeService();
        $this->assertEquals(10, $this->invoke($svc, 'nextBreakerSize', 9.0));
        $this->assertEquals(10, $this->invoke($svc, 'nextBreakerSize', 10.0));
        $this->assertEquals(16, $this->invoke($svc, 'nextBreakerSize', 10.1));
        $this->assertEquals(400, $this->invoke($svc, 'nextBreakerSize', 399.0));
    }

    // ── PE sizing ─────────────────────────────────────────────────────────────

    public function test_pe_sizing_three_bands_iec_60364_5_54(): void
    {
        $svc = $this->makeService();
        // Band 1: S ≤ 16 → S_PE = S
        $this->assertEquals(1.5,  $this->invoke($svc, 'peSizeForCable', 1.5));
        $this->assertEquals(10.0, $this->invoke($svc, 'peSizeForCable', 10.0));
        $this->assertEquals(16.0, $this->invoke($svc, 'peSizeForCable', 16.0));
        // Band 2: 16 < S ≤ 35 → S_PE = 16
        $this->assertEquals(16.0, $this->invoke($svc, 'peSizeForCable', 25.0));
        $this->assertEquals(16.0, $this->invoke($svc, 'peSizeForCable', 35.0));
        // Band 3: S > 35 → S_PE = S/2
        $this->assertEquals(25.0, $this->invoke($svc, 'peSizeForCable', 50.0));
        $this->assertEquals(35.0, $this->invoke($svc, 'peSizeForCable', 70.0));
    }

    // ── Breaker curve ─────────────────────────────────────────────────────────

    public function test_breaker_curves(): void
    {
        $svc = $this->makeService();
        $this->assertEquals('B', $this->invoke($svc, 'breakerCurve', 'LIGHTING', false));
        $this->assertEquals('C', $this->invoke($svc, 'breakerCurve', 'SOCKET',   false));
        // Non-motor HEAVY → C (not D); motor → D
        $this->assertEquals('C', $this->invoke($svc, 'breakerCurve', 'HEAVY', false));
        $this->assertEquals('D', $this->invoke($svc, 'breakerCurve', 'HEAVY', true));
        // AUXILIARY always gets C regardless of motor flag (motors that reach this type are small)
        $this->assertEquals('C', $this->invoke($svc, 'breakerCurve', 'AUXILIARY', false));
    }

    // ── Circuit sizing ─────────────────────────────────────────────────────────

    public function test_breaker_never_undersized_relative_to_ib(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // 3000 VA lighting — exceeds default lighting cap but must still give In ≥ Ib
        $result = $this->invoke($svc, 'sizeCircuit', 3000.0, false, 'LIGHTING', $rules);
        $this->assertGreaterThanOrEqual($result['ib_a'], $result['in_a']);
    }

    public function test_lighting_circuit_minimum_10A_and_1_5mm2(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // 12 VA lamp → Ib = 0.052 A — minimum floors must apply
        $result = $this->invoke($svc, 'sizeCircuit', 12.0, false, 'LIGHTING', $rules, false);
        $this->assertGreaterThanOrEqual(10, $result['in_a']);
        $this->assertGreaterThanOrEqual(1.5, $result['cable_mm2']);
        $this->assertEquals('B', $result['curve']);
    }

    public function test_socket_circuit_minimum_16A_and_2_5mm2(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // 100 VA socket → Ib = 0.43 A — minimum floors must apply
        $result = $this->invoke($svc, 'sizeCircuit', 100.0, false, 'SOCKET', $rules, false);
        $this->assertGreaterThanOrEqual(16, $result['in_a']);
        $this->assertGreaterThanOrEqual(2.5, $result['cable_mm2']);
        $this->assertEquals('C', $result['curve']);
    }

    public function test_heavy_non_motor_gets_curve_C(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        $result = $this->invoke($svc, 'sizeCircuit', 3000.0, false, 'HEAVY', $rules, false);
        $this->assertEquals('C', $result['curve']);
    }

    public function test_motor_load_gets_curve_D(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        $result = $this->invoke($svc, 'sizeCircuit', 3000.0, false, 'HEAVY', $rules, true);
        $this->assertEquals('D', $result['curve']);
    }

    // ── Cable size table (Phase 2 — float-key bug fixed) ──────────────────────

    public function test_cable_size_returns_correct_mm2(): void
    {
        $svc = $this->makeService();
        // 10 A In: 1.5 mm² → Iz=14.5 × 0.87=12.615 ≥ 10 → first match = 1.5
        $this->assertEquals(1.5, $this->invoke($svc, 'cableSizeForCurrentA', 10.0));
        // 16 A In: 1.5 mm² → 12.615 < 16; 2.5 mm² → 19.5×0.87=16.965 ≥ 16 → 2.5
        $this->assertEquals(2.5, $this->invoke($svc, 'cableSizeForCurrentA', 16.0));
    }

    // ── 3-phase dedicated circuit ──────────────────────────────────────────────

    public function test_3phase_load_gets_dedicated_heavy_circuit(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        $load = [
            'name'         => 'AC Unit',
            'qty'          => 1,
            'va'           => 5000.0,
            'va_each'      => 5000.0,
            'is3ph'        => true,
            'is_motor'     => false,
            'priority'     => 'normal',
            'circuit_type' => 'HEAVY',
        ];

        $circuit = $this->invoke($svc, 'buildSingleLoadCircuit', $load, $rules);

        $this->assertEquals('HEAVY', $circuit['type']);
        $this->assertTrue($circuit['is3ph']);
        $this->assertGreaterThanOrEqual($circuit['ib_a'], $circuit['in_a']);
    }

    // ── Floor-direct classification (Phase 1 fix) ─────────────────────────────

    public function test_floor_direct_light_classified_as_lighting_not_heavy(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        $loads = [[
            'name'         => 'LED Lamp',
            'qty'          => 1,
            'va'           => 12.0,
            'va_each'      => 12.0,
            'is3ph'        => false,
            'is_motor'     => false,
            'priority'     => 'normal',
            'circuit_type' => 'LIGHTING',
        ]];

        $circuits = $this->invoke($svc, 'buildDirectCircuits', $loads, $rules);

        $this->assertNotEmpty($circuits);
        $this->assertCount(1, array_filter($circuits, fn($c) => $c['type'] === 'LIGHTING'));
        $this->assertCount(0, array_filter($circuits, fn($c) => $c['type'] === 'HEAVY'));
    }

    // ── RCD policy ────────────────────────────────────────────────────────────

    public function test_socket_circuit_has_30ma_rcd(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        $loads = [
            ['name' => 'Socket', 'qty' => 4, 'va' => 400.0, 'va_each' => 100.0,
             'is3ph' => false, 'is_motor' => false, 'priority' => 'normal', 'circuit_type' => 'SOCKET'],
        ];

        $circuits = $this->invoke($svc, 'packSocketCircuits', $loads, 'Room 1', $rules);

        $this->assertCount(1, $circuits);
        $this->assertEquals('30mA', $circuits[0]['rcd']);
    }

    public function test_lighting_circuit_has_30ma_rcd(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        $loads = [
            ['name' => 'LED Lamp', 'qty' => 6, 'va' => 50.0, 'va_each' => 50.0 / 6,
             'is3ph' => false, 'is_motor' => false, 'priority' => 'normal', 'circuit_type' => 'LIGHTING'],
        ];

        $all  = [];
        $open = null;
        [$all, $open] = $this->invoke($svc, 'packLightingLoads', $loads, 'Room 1', $all, $open, $rules);

        if ($open !== null) {
            $c        = $this->invoke($svc, 'finalizeCircuit', $open, 'LIGHTING', $rules);
            $needsRcd = in_array($rules['rcd_policy'], ['30mA_socket_lighting', '30mA_all']);
            $c['rcd'] = $needsRcd ? '30mA' : 'none';
            $all[]    = $c;
        }

        $this->assertCount(1, $all);
        $this->assertEquals('30mA', $all[0]['rcd']);
    }

    // ── Socket circuit packing ─────────────────────────────────────────────────

    public function test_socket_circuit_splits_when_outlet_cap_exceeded(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        $loads = array_fill(0, 10, [
            'name' => 'Socket', 'qty' => 1, 'va' => 100.0, 'va_each' => 100.0,
            'is3ph' => false, 'is_motor' => false, 'priority' => 'normal', 'circuit_type' => 'SOCKET',
        ]);

        $circuits = $this->invoke($svc, 'packSocketCircuits', $loads, 'Room', $rules);

        // 10 outlets at max 8 per circuit → 2 circuits
        $this->assertCount(2, $circuits);
    }

    // ── Minimum lighting circuits (Phase 3) ───────────────────────────────────

    public function test_single_lighting_circuit_is_split_into_two(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // Build a single lighting circuit with 4 loads (well below VA cap)
        $circuit = [
            'type'        => 'LIGHTING',
            'is3ph'       => false,
            'room_names'  => ['Room A', 'Room B'],
            'loads'       => [
                ['name' => 'L1', 'qty' => 2, 'va_each' => 25.0, 'total_va' => 50.0, 'room' => 'Room A'],
                ['name' => 'L2', 'qty' => 2, 'va_each' => 25.0, 'total_va' => 50.0, 'room' => 'Room A'],
                ['name' => 'L3', 'qty' => 2, 'va_each' => 25.0, 'total_va' => 50.0, 'room' => 'Room B'],
                ['name' => 'L4', 'qty' => 2, 'va_each' => 25.0, 'total_va' => 50.0, 'room' => 'Room B'],
            ],
            'total_va'    => 200.0,
            'ib_a'        => 0.87,
            'in_a'        => 10,
            'curve'       => 'B',
            'cable_mm2'   => 1.5,
            'pe_mm2'      => 1.5,
            'rcd'         => '30mA',
            'rcd_group'   => 'RCCB-L',
            'has_critical'=> false,
            'vd_note'     => 'length required',
            'phase'       => 'A',
        ];

        $result = $this->invoke($svc, 'ensureMinLightingCircuits', [$circuit], $rules, true);

        $lighting = array_values(array_filter($result, fn($c) => $c['type'] === 'LIGHTING'));
        $this->assertCount(2, $lighting);
        $this->assertEquals('30mA', $lighting[0]['rcd']);
        $this->assertEquals('30mA', $lighting[1]['rcd']);
    }

    // ── Dedicated-room detection (Phase 3) ────────────────────────────────────

    public function test_dedicated_room_type_field_takes_precedence(): void
    {
        $svc = $this->makeService();

        // Matched by room.type
        $kitchen = new \stdClass();
        $kitchen->type = 'kitchen_residential';
        $kitchen->name = 'Room 1';
        $this->assertTrue($this->invoke($svc, 'isDedicatedRoom', $kitchen));

        // room.type 'laboratory' — should match
        $lab = new \stdClass();
        $lab->type = 'laboratory';
        $lab->name = 'L01';
        $this->assertTrue($this->invoke($svc, 'isDedicatedRoom', $lab));

        // room.type present but non-dedicated — name keyword must NOT override
        $office = new \stdClass();
        $office->type = 'office';
        $office->name = 'kitchen annex';  // name has 'kitchen' but type is 'office'
        $this->assertFalse($this->invoke($svc, 'isDedicatedRoom', $office));

        // type = null → fall back to name keyword
        $unnamed = new \stdClass();
        $unnamed->type = null;
        $unnamed->name = 'Server Room 1';
        $this->assertTrue($this->invoke($svc, 'isDedicatedRoom', $unnamed));

        // type = null, name has no keyword → not dedicated
        $plain = new \stdClass();
        $plain->type = null;
        $plain->name = 'Meeting Room A';
        $this->assertFalse($this->invoke($svc, 'isDedicatedRoom', $plain));
    }

    // ── CRITICAL / AUXILIARY classification ───────────────────────────────────

    public function test_critical_priority_hardwired_load_gets_critical_circuit_not_lighting(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // 500 VA server, hardwired, critical priority — must NOT fall through to LIGHTING
        $component = $this->mockComponent('Server', 500.0, 'critical', false, false);
        $load      = $this->invoke($svc, 'formatLoad', $component, 500.0, $rules);

        $this->assertEquals('CRITICAL', $load['circuit_type']);
        $this->assertNotEquals('LIGHTING', $load['circuit_type']);

        // The dedicated circuit it generates: type=CRITICAL, curve=C
        $circuit = $this->invoke($svc, 'buildSingleLoadCircuit', $load, $rules);
        $this->assertEquals('CRITICAL', $circuit['type']);
        $this->assertEquals('C', $circuit['curve']);
        $this->assertTrue($circuit['has_critical']);
    }

    public function test_non_light_hardwired_load_classified_as_auxiliary_not_lighting(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // Non-luminaire, non-socket, non-critical, non-heavy equipment → AUXILIARY (pooled)
        foreach (['Router', 'UPS', 'Security Camera', 'Computer'] as $typeName) {
            $component = $this->mockComponent($typeName, 300.0, 'normal', false, false);
            $load      = $this->invoke($svc, 'formatLoad', $component, 300.0, $rules);

            $this->assertEquals('AUXILIARY', $load['circuit_type'],
                "Expected AUXILIARY for type '{$typeName}', got '{$load['circuit_type']}'");
            $this->assertNotEquals('LIGHTING', $load['circuit_type'],
                "Type '{$typeName}' must not be classified as LIGHTING");
        }
    }

    public function test_luminaire_preset_names_classify_as_lighting(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        foreach (['Light', 'Fluorescent Lamp', 'LED Strip', 'Chandelier'] as $typeName) {
            $component = $this->mockComponent($typeName, 18.0, 'normal', false, false);
            $load      = $this->invoke($svc, 'formatLoad', $component, 18.0, $rules);

            $this->assertEquals('LIGHTING', $load['circuit_type'],
                "Expected LIGHTING for preset type '{$typeName}', got '{$load['circuit_type']}'");
        }
    }

    // ── Phase assignment ───────────────────────────────────────────────────────

    public function test_phase_assignment_uses_all_three_phases(): void
    {
        $svc = $this->makeService();

        $circuits = [
            ['type' => 'LIGHTING', 'is3ph' => false, 'total_va' => 500, 'phase' => null],
            ['type' => 'LIGHTING', 'is3ph' => false, 'total_va' => 500, 'phase' => null],
            ['type' => 'LIGHTING', 'is3ph' => false, 'total_va' => 500, 'phase' => null],
        ];

        $result = $this->invoke($svc, 'assignPhases', $circuits);
        $phases = array_column($result, 'phase');

        $this->assertContains('A', $phases);
        $this->assertContains('B', $phases);
        $this->assertContains('C', $phases);
    }

    public function test_3phase_circuit_marked_3PH_not_single_phase(): void
    {
        $svc = $this->makeService();

        $circuits = [
            ['type' => 'HEAVY',   'is3ph' => true,  'total_va' => 5000, 'phase' => null],
            ['type' => 'LIGHTING','is3ph' => false, 'total_va' => 500,  'phase' => null],
        ];

        $result = $this->invoke($svc, 'assignPhases', $circuits);
        $this->assertEquals('3PH', $result[0]['phase']);
        $this->assertContains($result[1]['phase'], ['A', 'B', 'C']);
    }

    // ── AUXILIARY pool — small motor / wasted-circuit reduction ───────────────

    public function test_small_motor_below_threshold_classified_as_auxiliary(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // 70 VA ceiling fan: is_motor=true but 70 < motor_dedicated_threshold_va (750)
        // Must NOT become HEAVY — goes to the pooled AUXILIARY type
        $component = $this->mockComponent('Ceiling Fan', 70.0, 'normal', false, true);
        $load      = $this->invoke($svc, 'formatLoad', $component, 70.0, $rules);

        $this->assertEquals('AUXILIARY', $load['circuit_type']);
        $this->assertNotEquals('HEAVY', $load['circuit_type'],
            'Small motor below motor_dedicated_threshold_va must not get a dedicated HEAVY circuit');
    }

    public function test_large_motor_above_threshold_is_heavy(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // 1500 VA pump: is_motor=true AND 1500 ≥ motor_dedicated_threshold_va (750) → HEAVY
        $component = $this->mockComponent('Water Pump', 1500.0, 'normal', false, true);
        $load      = $this->invoke($svc, 'formatLoad', $component, 1500.0, $rules);

        $this->assertEquals('HEAVY', $load['circuit_type']);
    }

    public function test_multiple_aux_loads_across_rooms_share_one_circuit(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // 3 tiny loads from different rooms, total = 105 VA — well inside 10 A × 230 V × 0.80 = 1840 VA cap
        $auxLoads = [
            ['name' => 'Ceiling Fan',   'qty' => 1, 'va' => 70.0,  'va_each' => 70.0,  'is3ph' => false,
             'is_motor' => true,  'priority' => 'normal', 'circuit_type' => 'AUXILIARY'],
            ['name' => 'Speaker',       'qty' => 1, 'va' => 20.0,  'va_each' => 20.0,  'is3ph' => false,
             'is_motor' => false, 'priority' => 'normal', 'circuit_type' => 'AUXILIARY'],
            ['name' => 'Motion Sensor', 'qty' => 1, 'va' => 15.0,  'va_each' => 15.0,  'is3ph' => false,
             'is_motor' => false, 'priority' => 'normal', 'circuit_type' => 'AUXILIARY'],
        ];

        $all  = [];
        $open = null;

        // Simulate 3 separate rooms contributing to the cross-room accumulator
        [$all, $open] = $this->invoke($svc, 'packAuxiliaryLoads',
            [$auxLoads[0]], 'Office A', $all, $open, $rules);
        [$all, $open] = $this->invoke($svc, 'packAuxiliaryLoads',
            [$auxLoads[1]], 'Corridor',  $all, $open, $rules);
        [$all, $open] = $this->invoke($svc, 'packAuxiliaryLoads',
            [$auxLoads[2]], 'Office B',  $all, $open, $rules);

        // All 3 loads should still be in the SAME open accumulator (not closed yet)
        $this->assertEmpty($all, 'No circuits should be closed until the accumulator is full');
        $this->assertNotNull($open);
        $this->assertCount(3, $open['loads']);
        $this->assertEqualsWithDelta(105.0, $open['total_va'], 0.1);

        // Verify room names span multiple rooms
        $this->assertContains('Office A', $open['room_names']);
        $this->assertContains('Corridor',  $open['room_names']);
        $this->assertContains('Office B',  $open['room_names']);

        // Close and verify the finalised circuit is AUXILIARY
        $circuit = $this->invoke($svc, 'finalizeCircuit', $open, 'AUXILIARY', $rules);
        $this->assertEquals('AUXILIARY', $circuit['type']);
        $this->assertCount(3, $circuit['loads']);
    }

    public function test_auxiliary_circuit_count_less_than_one_per_load(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // 5 × 50 VA loads — 5 dedicated circuits under old code; should merge into 1 now
        // Total = 250 VA << 1840 VA cap → exactly 1 circuit
        $loads = array_fill(0, 5, [
            'name' => 'Ceiling Fan', 'qty' => 1, 'va' => 50.0, 'va_each' => 50.0,
            'is3ph' => false, 'is_motor' => true, 'priority' => 'normal', 'circuit_type' => 'AUXILIARY',
        ]);

        $all  = [];
        $open = null;
        [$all, $open] = $this->invoke($svc, 'packAuxiliaryLoads', $loads, 'Room', $all, $open, $rules);
        if ($open !== null) {
            $all[] = $this->invoke($svc, 'finalizeCircuit', $open, 'AUXILIARY', $rules);
        }

        $this->assertCount(1, $all, '5 small aux loads must share 1 circuit, not get 5 dedicated circuits');
        $this->assertCount(5, $all[0]['loads']);
    }

    public function test_auxiliary_circuit_splits_when_cap_exceeded(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // Each load = 700 VA; cap = 10 A × 230 V × 0.80 = 1840 VA
        // First load: 700 VA ✓; second: 1400 VA ✓; third: 2100 VA > 1840 → split after 2nd
        $loads = array_fill(0, 3, [
            'name' => 'Large Aux Device', 'qty' => 1, 'va' => 700.0, 'va_each' => 700.0,
            'is3ph' => false, 'is_motor' => false, 'priority' => 'normal', 'circuit_type' => 'AUXILIARY',
        ]);

        $all  = [];
        $open = null;
        [$all, $open] = $this->invoke($svc, 'packAuxiliaryLoads', $loads, 'Room', $all, $open, $rules);
        if ($open !== null) {
            $all[] = $this->invoke($svc, 'finalizeCircuit', $open, 'AUXILIARY', $rules);
        }

        $this->assertCount(2, $all, '3 × 700 VA must produce 2 circuits (2+1), not 3 dedicated');
    }

    public function test_wet_room_lighting_joins_floor_pool(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // Simulate lighting from a kitchen (dedicated room type) and an office
        // flowing through the SAME cross-room lighting accumulator.
        // After the fix: dedicated rooms no longer isolate their lighting.
        $kitchenLights = [[
            'name' => 'Kitchen Light', 'qty' => 2, 'va' => 40.0, 'va_each' => 20.0,
            'is3ph' => false, 'is_motor' => false, 'priority' => 'normal', 'circuit_type' => 'LIGHTING',
        ]];
        $officeLights = [[
            'name' => 'Office Light', 'qty' => 4, 'va' => 80.0, 'va_each' => 20.0,
            'is3ph' => false, 'is_motor' => false, 'priority' => 'normal', 'circuit_type' => 'LIGHTING',
        ]];

        $all  = [];
        $open = null;

        [$all, $open] = $this->invoke($svc, 'packLightingLoads',
            $kitchenLights, 'Kitchen', $all, $open, $rules);
        [$all, $open] = $this->invoke($svc, 'packLightingLoads',
            $officeLights, 'Office', $all, $open, $rules);

        // Both rooms' lighting must be in the same open accumulator (cross-room)
        $this->assertEmpty($all, 'No circuits should be closed; both rooms fit in one accumulator');
        $this->assertNotNull($open);
        $this->assertEqualsWithDelta(120.0, $open['total_va'], 0.1);
        $this->assertContains('Kitchen', $open['room_names']);
        $this->assertContains('Office',  $open['room_names']);
    }

    public function test_grouped_circuit_does_not_exceed_80pct_utilisation(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        // Fill aux pool to 1800 VA (just under the 1840 VA cap):
        // Ib = 1800/230 ≈ 7.83 A; In = max(10, 10) = 10 A; util = 78% ≤ 80%
        $loads = [[
            'name' => 'Equipment', 'qty' => 1, 'va' => 1800.0, 'va_each' => 1800.0,
            'is3ph' => false, 'is_motor' => false, 'priority' => 'normal', 'circuit_type' => 'AUXILIARY',
        ]];

        $all  = [];
        $open = null;
        [$all, $open] = $this->invoke($svc, 'packAuxiliaryLoads', $loads, 'Room', $all, $open, $rules);
        if ($open !== null) {
            $all[] = $this->invoke($svc, 'finalizeCircuit', $open, 'AUXILIARY', $rules);
        }

        foreach ($all as $circuit) {
            $this->assertArrayHasKey('utilisation_pct', $circuit);
            $this->assertLessThanOrEqual(80, $circuit['utilisation_pct'],
                "Circuit utilisation {$circuit['utilisation_pct']}% exceeds 80% packing cap");
        }
    }

    public function test_every_circuit_type_has_utilisation_pct(): void
    {
        $svc   = $this->makeService();
        $rules = $this->defaultRules();

        $acc = [
            'total_va'    => 500.0,
            'loads'       => [['name' => 'L', 'qty' => 1, 'va_each' => 500.0, 'total_va' => 500.0]],
            'room_names'  => ['Room'],
            'has_critical'=> false,
        ];

        foreach (['LIGHTING', 'SOCKET', 'AUXILIARY'] as $type) {
            $c = $this->invoke($svc, 'finalizeCircuit', $acc, $type, $rules);
            $this->assertArrayHasKey('utilisation_pct', $c,
                "Circuit type {$type} is missing utilisation_pct");
            $this->assertIsInt($c['utilisation_pct']);
            $this->assertGreaterThan(0, $c['utilisation_pct']);
        }

        // Dedicated (single-load) circuits also carry utilisation_pct
        $load = [
            'name'         => 'Motor',
            'qty'          => 1,
            'va'           => 2000.0,
            'va_each'      => 2000.0,
            'is3ph'        => false,
            'is_motor'     => true,
            'priority'     => 'normal',
            'circuit_type' => 'HEAVY',
        ];
        $circuit = $this->invoke($svc, 'buildSingleLoadCircuit', $load, $rules);
        $this->assertArrayHasKey('utilisation_pct', $circuit,
            'buildSingleLoadCircuit must include utilisation_pct');
        $this->assertIsInt($circuit['utilisation_pct']);
    }

    // ── Task 4 — Cable ampacity / voltage-drop / group_small_critical ─────────

    /**
     * Spot-check CABLE_AMPACITY against IEC 60364-5-52 Table B.52.2,
     * Method A1 (conduit in thermally insulated wall), 2 loaded conductors,
     * copper 70°C PVC, 30°C ambient reference.
     */
    public function test_cable_ampacity_matches_iec_b52_3_2core(): void
    {
        $svc    = $this->makeService();
        $ref    = new \ReflectionClass($svc);
        $actual = $ref->getConstant('CABLE_AMPACITY');

        $expected = [
            '1.5'  => 14.5,
            '2.5'  => 19.5,
            '4'    => 26.0,
            '6'    => 34.0,
            '10'   => 46.0,
            '16'   => 61.0,
            '25'   => 80.0,
            '35'   => 99.0,
            '50'   => 119.0,
            '70'   => 151.0,
            '95'   => 182.0,
            '120'  => 210.0,
        ];

        foreach ($expected as $mm2 => $iz) {
            $this->assertArrayHasKey($mm2, $actual,
                "CABLE_AMPACITY missing key '{$mm2}'");
            $this->assertEqualsWithDelta($iz, $actual[$mm2], 0.01,
                "CABLE_AMPACITY['{$mm2}'] should be {$iz} A per IEC B.52.2 Method A1 2-conductor");
        }
    }

    /**
     * ΔU% = (mV/A/m) × Ib × L / 1000 / V_nominal × 100
     * 2.5 mm²: mV/A/m = 18; Ib = 6 A; L = 20 m; 1-phase (V_nominal = 230)
     * ΔU = 18 × 6 × 20 / 1000 = 2.16 V  →  ΔU% = 2.16 / 230 × 100 = 0.94%
     */
    public function test_voltage_drop_computes_correctly_for_known_length(): void
    {
        $svc    = $this->makeService();
        $result = $this->invoke($svc, 'computeVoltageDrop', 2.5, 6.0, 20.0, false, 'SOCKET');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(2.16, $result['vd_v'],   0.01);
        $this->assertEqualsWithDelta(0.94, $result['vd_pct'], 0.01);
        $this->assertEquals(5.0, $result['vd_limit_pct']);   // SOCKET limit
        $this->assertFalse($result['vd_warn']);               // 0.94% < 5%
        $this->assertNull($result['vd_remedy_cable_mm2']);
    }

    /** computeVoltageDrop must return null and not crash when length_m is null. */
    public function test_voltage_drop_null_safe_when_length_missing(): void
    {
        $svc    = $this->makeService();
        $result = $this->invoke($svc, 'computeVoltageDrop', 2.5, 6.0, null, false, 'SOCKET');

        $this->assertNull($result, 'computeVoltageDrop must return null when length_m is null');
    }

    /**
     * LIGHTING limit is 3%.
     * 1.5 mm²: mV/A/m = 29; Ib = 10 A; L = 25 m; 1-phase
     * ΔU = 29 × 10 × 25 / 1000 = 7.25 V  →  ΔU% = 7.25 / 230 × 100 = 3.15% > 3%  ⚠
     * Remedy: 2.5 mm² → ΔU = 18 × 10 × 25 / 1000 = 4.5 V → 1.96% ≤ 3% ✓
     */
    public function test_lighting_vd_over_3pct_warns(): void
    {
        $svc    = $this->makeService();
        $result = $this->invoke($svc, 'computeVoltageDrop', 1.5, 10.0, 25.0, false, 'LIGHTING');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(3.15, $result['vd_pct'], 0.05);
        $this->assertEquals(3.0, $result['vd_limit_pct']);
        $this->assertTrue($result['vd_warn'],
            'LIGHTING ΔU% of ~3.15% must trigger vd_warn=true');
        $this->assertEquals(2.5, $result['vd_remedy_cable_mm2'],
            'Remedy for 1.5mm² over limit should be 2.5mm²');
    }

    /**
     * With group_small_critical=true:
     *   - 3 small critical loads (va_each < 750) on a floor → ONE CRITICAL circuit
     *   - 1 large critical load (va_each ≥ 750) stays dedicated → separate circuit
     * Total CRITICAL circuits = 2 (not 4).
     */
    public function test_group_small_critical_true_merges_small_critical_loads(): void
    {
        $svc   = $this->makeService();
        $rules = array_merge($this->defaultRules(), ['group_small_critical' => true]);

        $smallCritical = [
            'name' => 'Critical Sensor', 'qty' => 1, 'va' => 200.0, 'va_each' => 200.0,
            'is3ph' => false, 'is_motor' => false, 'priority' => 'critical', 'circuit_type' => 'CRITICAL',
        ];
        $largeCritical = [
            'name' => 'UPS', 'qty' => 1, 'va' => 1000.0, 'va_each' => 1000.0,
            'is3ph' => false, 'is_motor' => false, 'priority' => 'critical', 'circuit_type' => 'CRITICAL',
        ];

        // Mix of 3 small + 1 large critical loads
        $loads = [
            array_merge($smallCritical, ['name' => 'Critical Sensor A']),
            array_merge($smallCritical, ['name' => 'Critical Sensor B']),
            array_merge($smallCritical, ['name' => 'Critical Sensor C']),
            $largeCritical,
        ];

        $circuits = $this->invoke($svc, 'buildDirectCircuits', $loads, $rules);

        $criticalCircuits = array_values(array_filter($circuits, fn($c) => $c['type'] === 'CRITICAL'));

        // group_small_critical=true: 3 small → 1 shared; 1 large → 1 dedicated = 2 total
        $this->assertCount(2, $criticalCircuits,
            'group_small_critical=true must merge 3 small criticals into 1 circuit + 1 for the large');

        // The shared circuit should hold 3 loads
        $sharedCircuit = null;
        foreach ($criticalCircuits as $c) {
            if (count($c['loads']) > 1) { $sharedCircuit = $c; break; }
        }
        $this->assertNotNull($sharedCircuit, 'One of the CRITICAL circuits must be the shared grouping');
        $this->assertCount(3, $sharedCircuit['loads'],
            'Shared CRITICAL circuit must contain all 3 small critical loads');
        $this->assertEqualsWithDelta(600.0, $sharedCircuit['total_va'], 0.1);

        // CRITICAL circuits must never have a 30mA RCD under 30mA_socket_lighting policy
        foreach ($criticalCircuits as $c) {
            $this->assertEquals('none', $c['rcd'],
                "CRITICAL circuit must not have a 30mA RCD under '30mA_socket_lighting' policy");
        }
    }
}
