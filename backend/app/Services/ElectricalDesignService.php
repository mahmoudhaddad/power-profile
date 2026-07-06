<?php

namespace App\Services;

use App\Models\Project;

/**
 * IEC 60364 panel-schedule / electrical design service.
 *
 * System:      230/400 V, 50 Hz, PVC/copper cables.
 * Ambient:     40 °C (Palestine / Arab-region design temperature).
 * Install:     Method A1 — conductors in conduit in a thermally insulated wall.
 *              (Most conservative reference method; adopted to give a built-in safety margin.)
 *
 * ⚠  All ampacity values are taken from IEC 60364-5-52 Table B.52.2 (PVC/Cu, 2 loaded conductors).
 *    Verify against the current edition before using in a licensed submission.
 *
 * Key formula note
 * ─────────────────
 * `power` field stores APPARENT power in VA (nameplate S, already includes PF).
 * Design current:
 *   1-phase  Ib = S / V_phase   = S / 230      [A]
 *   3-phase  Ib = S / (√3 × Vl) = S / (√3×400) [A]
 * The breaker and cable must carry the full apparent current (VA÷V), NOT the
 * active component (W÷V).  Do NOT multiply by power_factor here.
 *
 * Circuit-type vocabulary
 * ────────────────────────
 * HEAVY     — large motor / 3-phase / VA ≥ heavy_threshold → dedicated circuit, curve C or D
 * CRITICAL  — critical-priority hardwired load (server, life-safety) → dedicated, curve C
 * SOCKET    — plug-in device (needs_socket) → packed per-room, 16 A / 2.5 mm² min
 * LIGHTING  — confirmed luminaire → cross-room packed, 10 A / 1.5 mm² min, curve B
 * AUXILIARY — small motor, fan, speaker, sensor, PoE device below threshold →
 *             cross-room packed (never mixed with lighting/sockets), 10 A / 1.5 mm² min, curve C
 *
 * Voltage-drop method
 * ────────────────────
 * ΔU (V)   = (mV/A/m) × Ib × length_m / 1000
 * ΔU (%)   = ΔU / V_nominal × 100    (V_nominal: 230 single-phase, 400 three-phase)
 * Limits:  3 % for LIGHTING, 5 % for all other circuit types (IEC 60364-8-1 Table 1).
 * mV/A/m values are stored in CABLE_VD_MV_A_M (2-conductor, Cu 70°C PVC, ⚠ VERIFY).
 */
class ElectricalDesignService
{
    // ── System constants ───────────────────────────────────────────────────────
    private const V_PHASE   = 230.0;
    private const V_LINE    = 400.0;
    private const SQRT3     = 1.7320508;
    private const AMBIENT_C = 40;

    // IEC 60364-5-52 Table B.52.14 — PVC at 40 °C / 70 °C conductor limit
    // Cf = sqrt((70−40)/(70−30)) = sqrt(0.75) ≈ 0.866 → tabled as 0.87
    // ⚠ VERIFY IEC 60364-5-52 Table B.52.14
    private const DERATING_40C = 0.87;

    // IEC 60364-5-52 Table B.52.2 — Method A1 (conductors in conduit in a thermally insulated wall),
    // PVC/Cu, 2 loaded conductors, 30°C ambient reference.
    // Adopted as the most conservative reference method to give a built-in safety margin.
    // Values here are the 2-CORE (single-phase L+N) column — correct for LIGHTING, SOCKET, AUXILIARY, CRITICAL.
    // ⚠ For 3-phase HEAVY circuits the 3/4-core column is ~8–11% lower; the 80% loading factor
    //   partly compensates.  No grouping derating applied (assumes one circuit per conduit;
    //   see IEC 60364-5-52 Table B.52.17 if multiple circuits share one enclosure).
    // String keys avoid PHP float-to-int key truncation (1.5→1, 2.5→2 bug).
    private const CABLE_AMPACITY = [
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

    // Voltage-drop millivolt per ampere per metre — single-phase, 2-conductor path,
    // Cu 70°C PVC, Method A1 (conduit in thermally insulated wall).  Derived from resistance +
    // inductance per IEC 60364-5-52 / BS 7671 Appendix 4, Table 4D2B.
    // ⚠ VERIFY each value against IEC 60364-5-52 Table B.52 (mV/A/m column) before
    //   using in a licensed voltage-drop submission.  Values for ≥25 mm² include a
    //   small inductive component that the pure-resistance formula under-estimates.
    private const CABLE_VD_MV_A_M = [
        '1.5'  => 29.0,   // ⚠ VERIFY
        '2.5'  => 18.0,   // ⚠ VERIFY
        '4'    => 11.0,   // ⚠ VERIFY
        '6'    =>  7.3,   // ⚠ VERIFY
        '10'   =>  4.4,   // ⚠ VERIFY
        '16'   =>  2.8,   // ⚠ VERIFY
        '25'   =>  1.75,  // ⚠ VERIFY (inductive component included)
        '35'   =>  1.25,  // ⚠ VERIFY
        '50'   =>  0.93,  // ⚠ VERIFY
        '70'   =>  0.63,  // ⚠ VERIFY
        '95'   =>  0.47,  // ⚠ VERIFY
        '120'  =>  0.37,  // ⚠ VERIFY
    ];

    // IEC 60898 / IEC 60947-2 standard MCB current ratings [A]
    private const BREAKER_SIZES = [6, 10, 16, 20, 25, 32, 40, 50, 63, 80, 100, 125, 160, 200, 250, 315, 400];

    // ── Balance / split tuning constants ──────────────────────────────────────
    // Phase 1 trigger: split when circuit_VA > breaker_a × V_PHASE × LOADING_FACTOR
    private const LOADING_FACTOR   = 0.80;   // ⚠ tunable — breaker utilisation ceiling
    // Phase 3 loop: keep re-splitting until imbalance ≤ this fraction (0.15 = 15 %)
    private const IMBALANCE_TARGET = 0.15;   // ⚠ tunable
    // Maximum balance-driven re-split iterations per floor
    private const MAX_ITERS        = 12;     // ⚠ tunable
    // Minimum VA per sub-circuit after a fixture-level split (avoids micro-circuits)
    private const MIN_SPLIT_VA     = 150.0;  // ⚠ tunable

    // Wet/critical room types: their SOCKET circuits are isolated per-room (not cross-room shared).
    // Their LIGHTING joins the normal floor pool — only sockets/equipment stay isolated.
    private const DEDICATED_ROOM_TYPES = [
        'kitchen_residential', 'kitchen_commercial', 'bathroom',
        'laboratory', 'workshop', 'server_room', 'operating_theater',
    ];

    private const DEDICATED_ROOM_NAME_KEYWORDS = [
        'kitchen', 'bathroom', 'lab', 'workshop', 'server', 'critical', 'operating',
    ];

    private const LUMINAIRE_PRESET_NAMES = [
        'Light', 'Fluorescent Lamp', 'LED Strip', 'Chandelier',
    ];

    private const LUMINAIRE_KEYWORDS = [
        'light', 'lamp', 'led strip', 'chandelier', 'luminaire',
        'lantern', 'sconce', 'bulb', 'pendant', 'downlight',
        'spotlight', 'fluorescent', 'fixture',
    ];

    // ── Per-building-type design rules ─────────────────────────────────────────
    private const DESIGN_RULES = [
        'hospital' => [
            'heavy_threshold_va'           => 1500,   // ⚠ tunable
            'motor_dedicated_threshold_va' => 750,    // ⚠ tunable — motors below this join auxiliary pool
            'socket_outlets_per_circuit'   => 6,
            'lighting_breaker_a'           => 10,
            'socket_breaker_a'             => 16,
            'auxiliary_breaker_a'          => 10,
            'breaker_loading_factor'       => 0.80,
            'spare_capacity_pct'           => 20,
            'rcd_policy'                   => '30mA_all',
            'essential_separation'         => true,
            'allow_mixed_general_circuits' => false,
            'group_small_critical'         => false,
            'standard_ref'                 => 'IEC 60364 + HTM 06-01',
        ],
        'data_center' => [
            'heavy_threshold_va'           => 1000,   // ⚠ tunable
            'motor_dedicated_threshold_va' => 500,    // ⚠ tunable
            'socket_outlets_per_circuit'   => 4,
            'lighting_breaker_a'           => 10,
            'socket_breaker_a'             => 16,
            'auxiliary_breaker_a'          => 10,
            'breaker_loading_factor'       => 0.70,
            'spare_capacity_pct'           => 30,
            'rcd_policy'                   => '30mA_socket_lighting',
            'essential_separation'         => true,
            'allow_mixed_general_circuits' => false,
            'group_small_critical'         => false,
            'standard_ref'                 => 'IEC 60364 + EN 50600',
        ],
        'office' => [
            'heavy_threshold_va'           => 2000,   // ⚠ tunable
            'motor_dedicated_threshold_va' => 750,    // ⚠ tunable
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
        ],
        'residential' => [
            'heavy_threshold_va'           => 2000,   // ⚠ tunable
            'motor_dedicated_threshold_va' => 750,    // ⚠ tunable
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
        ],
        'school' => [
            'heavy_threshold_va'           => 2000,   // ⚠ tunable
            'motor_dedicated_threshold_va' => 750,    // ⚠ tunable
            'socket_outlets_per_circuit'   => 8,
            'lighting_breaker_a'           => 10,
            'socket_breaker_a'             => 16,
            'auxiliary_breaker_a'          => 10,
            'breaker_loading_factor'       => 0.80,
            'spare_capacity_pct'           => 15,
            'rcd_policy'                   => '30mA_socket_lighting',
            'essential_separation'         => false,
            'allow_mixed_general_circuits' => false,
            'group_small_critical'         => false,
            'standard_ref'                 => 'IEC 60364',
        ],
        'mosque' => [
            'heavy_threshold_va'           => 2000,   // ⚠ tunable
            'motor_dedicated_threshold_va' => 750,    // ⚠ tunable
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
        ],
        'mall' => [
            'heavy_threshold_va'           => 3000,   // ⚠ tunable
            'motor_dedicated_threshold_va' => 1000,   // ⚠ tunable
            'socket_outlets_per_circuit'   => 8,
            'lighting_breaker_a'           => 16,
            'socket_breaker_a'             => 16,
            'auxiliary_breaker_a'          => 10,
            'breaker_loading_factor'       => 0.80,
            'spare_capacity_pct'           => 15,
            'rcd_policy'                   => '30mA_socket_lighting',
            'essential_separation'         => false,
            'allow_mixed_general_circuits' => false,
            'group_small_critical'         => false,
            'standard_ref'                 => 'IEC 60364',
        ],
    ];

    private const DEFAULT_RULES = [
        'heavy_threshold_va'           => 2000,   // ⚠ tunable
        'motor_dedicated_threshold_va' => 750,    // ⚠ tunable — motors below this join auxiliary pool
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

    // ── Public entry point ─────────────────────────────────────────────────────

    public function analyzeProject(Project $project): array
    {
        $projectBuildingType = $project->building_type ?? null;
        $projectRules        = self::DESIGN_RULES[$projectBuildingType] ?? self::DEFAULT_RULES;

        $buildings = $project->buildings()->with([
            'components.componentType',
            'floors.components.componentType',
            'floors.rooms.components.componentType',
        ])->get();

        $buildingResults = [];
        foreach ($buildings as $building) {
            $bType  = $building->type ?? $projectBuildingType;
            $rules  = self::DESIGN_RULES[$bType] ?? self::DEFAULT_RULES;
            $bDfs   = DiversityFactorService::buildingDfs($bType);
            $buildingResults[] = $this->analyzeBuilding($building, $rules, $bDfs);
        }

        return [
            'standard_ref'    => $projectRules['standard_ref'],
            'system_voltage'  => '230/400 V',
            'frequency_hz'    => 50,
            'ambient_temp_c'  => self::AMBIENT_C,
            'cable_type'      => 'PVC/copper',
            'install_method'  => 'A1 — conductors in conduit in thermally insulated wall (IEC 60364-5-52 Table B.52.2, most conservative)',
            'derating_factor' => self::DERATING_40C,
            'cable_vd_table'  => self::CABLE_VD_MV_A_M,  // for client-side VD remedy lookup
            'buildings'       => $buildingResults,
        ];
    }

    // ── Building ───────────────────────────────────────────────────────────────

    private function analyzeBuilding(object $building, array $rules, array $bDfs): array
    {
        $buildingOwnLoads    = $this->extractLoads($building->components, $rules);
        $buildingOwnCircuits = $this->buildDirectCircuits($buildingOwnLoads, $rules);
        $buildingOwnVA       = array_sum(array_column($buildingOwnLoads, 'va'));

        $floorResults   = [];
        $floorDivVAs    = [];
        $nameplateTotal = $buildingOwnVA;

        foreach ($building->getRelation('floors') as $floor) {
            $floorResult     = $this->analyzeFloor($floor, $rules, $bDfs);
            $floorResults[]  = $floorResult;
            $floorDivVAs[]   = $floorResult['db']['total_va_diversified'];
            $nameplateTotal += $floorResult['db']['total_va_nameplate'];
        }

        $buildingDivVA  = array_sum($floorDivVAs) * $bDfs['floor_to_building'] + $buildingOwnVA;
        $incomerVA      = $buildingDivVA * (1.0 + $rules['spare_capacity_pct'] / 100.0);
        $mdbIncomer     = $this->sizeIncomer($incomerVA, true, $rules);

        $floorFeeders   = $this->makeFloorFeeders($floorResults, $rules);
        $mdbCircuits    = array_merge($buildingOwnCircuits, $floorFeeders);
        $mdbCircuits    = $this->splitOversizedCircuits($mdbCircuits, $rules);
        $allMdbCircuits = $this->assignPhases($mdbCircuits);
        foreach ($allMdbCircuits as $i => &$c) { $c['circuit_no'] = $i + 1; }
        unset($c);

        $phaseBalance   = $this->computePhaseBalance($allMdbCircuits);
        $essentialPanel = $rules['essential_separation']
            ? $this->extractEssentialPanel($floorResults, $rules)
            : null;

        return [
            'id'    => $building->id,
            'name'  => $building->name,
            'type'  => $building->type,
            'rules' => $rules,
            'mdb'   => [
                'total_va_nameplate'   => round($nameplateTotal, 1),
                'total_va_diversified' => round($buildingDivVA, 1),
                'incomer_ib_a'         => $mdbIncomer['ib_a'],
                'incomer_in_a'         => $mdbIncomer['in_a'],
                'incomer_cable_mm2'    => $mdbIncomer['cable_mm2'],
                'incomer_pe_mm2'       => $mdbIncomer['pe_mm2'],
                'incomer_curve'        => 'C',
                'phase_balance_va'     => $phaseBalance,
                'phase_imbalance_pct'  => $this->imbalancePct($phaseBalance),
            ],
            'mdb_circuits'    => array_values($allMdbCircuits),
            'essential_panel' => $essentialPanel,
            'floors'          => $floorResults,
        ];
    }

    // ── Floor ──────────────────────────────────────────────────────────────────

    private function analyzeFloor(object $floor, array $rules, array $bDfs): array
    {
        $floorOwnLoads = $this->extractLoads($floor->components, $rules);
        $floorOwnVA    = array_sum(array_column($floorOwnLoads, 'va'));

        $roomSummaries  = [];
        $allCircuits    = [];
        $smallCriticals = [];  // for group_small_critical: collected across all rooms
        $roomDivVASum   = 0.0;
        $nameplateSum   = $floorOwnVA;
        $openLighting   = null;
        $openAuxiliary  = null;
        $needsRcd       = in_array($rules['rcd_policy'], ['30mA_socket_lighting', '30mA_all']);
        $needsRcdAux    = ($rules['rcd_policy'] === '30mA_all');
        $allowMixed     = $rules['allow_mixed_general_circuits'] ?? false;
        $groupSmall     = $rules['group_small_critical'] ?? false;
        $motorThreshold = $rules['motor_dedicated_threshold_va'] ?? 750;

        $rooms     = $floor->rooms;
        $roomCount = count($rooms);
        $midRoom   = (int) ceil($roomCount / 2);
        $roomIdx   = 0;

        foreach ($rooms as $room) {
            $roomIdx++;
            $isDedicated = $this->isDedicatedRoom($room);
            $roomDf      = DiversityFactorService::roomDf($room->type ?? null);
            $loads       = $this->extractLoads($room->components, $rules);
            $roomVA      = array_sum(array_column($loads, 'va'));

            $nameplateSum += $roomVA;
            $roomDivVASum += $roomVA * $roomDf * $bDfs['room_to_floor'];

            $heavyLoads    = array_values(array_filter($loads, fn($l) => $l['circuit_type'] === 'HEAVY'));
            $criticalLoads = array_values(array_filter($loads, fn($l) => $l['circuit_type'] === 'CRITICAL'));
            $sockets       = array_values(array_filter($loads, fn($l) => $l['circuit_type'] === 'SOCKET'));
            $lights        = array_values(array_filter($loads, fn($l) => $l['circuit_type'] === 'LIGHTING'));
            $auxLoads      = array_values(array_filter($loads, fn($l) => $l['circuit_type'] === 'AUXILIARY'));

            // HEAVY: always one dedicated circuit per load
            foreach ($heavyLoads as $load) {
                $c = $this->buildSingleLoadCircuit($load, $rules);
                $c['room_names'] = [$room->name];
                $allCircuits[] = $c;
            }

            // CRITICAL: dedicated, or collected for floor-wide grouping when group_small_critical=true
            foreach ($criticalLoads as $load) {
                if ($groupSmall && $load['va_each'] < $motorThreshold) {
                    $smallCriticals[] = array_merge($load, ['_room' => $room->name]);
                } else {
                    $c = $this->buildSingleLoadCircuit($load, $rules);
                    $c['room_names'] = [$room->name];
                    $allCircuits[] = $c;
                }
            }

            if ($allowMixed && !$isDedicated) {
                // Mixed mode (non-dedicated rooms only): lighting + sockets combined
                $mixed = array_merge($lights, $sockets);
                foreach ($this->packSocketCircuits($mixed, $room->name, $rules, 'MIXED') as $c) {
                    $allCircuits[] = $c;
                }
            } else {
                // Normal mode — including dedicated rooms, whose lighting joins the floor pool
                foreach ($this->packSocketCircuits($sockets, $room->name, $rules) as $c) {
                    $allCircuits[] = $c;
                }
                [$allCircuits, $openLighting] = $this->packLightingLoads(
                    $lights, $room->name, $allCircuits, $openLighting, $rules
                );
                // Force-close at floor midpoint to guarantee ≥2 lighting circuits
                if ($roomIdx === $midRoom && $openLighting !== null) {
                    $c = $this->finalizeCircuit($openLighting, 'LIGHTING', $rules);
                    $c['rcd'] = $needsRcd ? '30mA' : 'none';
                    $allCircuits[] = $c;
                    $openLighting  = null;
                }
            }

            // AUXILIARY: always cross-room for all rooms
            [$allCircuits, $openAuxiliary] = $this->packAuxiliaryLoads(
                $auxLoads, $room->name, $allCircuits, $openAuxiliary, $rules
            );

            $roomSummaries[] = [
                'id'        => $room->id,
                'name'      => $room->name,
                'type'      => $room->type,
                'total_va'  => round($roomVA, 1),
                'dedicated' => $isDedicated,
            ];
        }

        // Close last open cross-room lighting circuit
        if ($openLighting !== null) {
            $c = $this->finalizeCircuit($openLighting, 'LIGHTING', $rules);
            $c['rcd'] = $needsRcd ? '30mA' : 'none';
            $allCircuits[] = $c;
        }

        // Close last open auxiliary circuit
        if ($openAuxiliary !== null) {
            $c = $this->finalizeCircuit($openAuxiliary, 'AUXILIARY', $rules);
            $c['rcd'] = $needsRcdAux ? '30mA' : 'none';
            $allCircuits[] = $c;
        }

        // Pack grouped small critical loads into one floor-wide circuit
        if ($groupSmall && !empty($smallCriticals)) {
            $roomNames = array_values(array_unique(array_column($smallCriticals, '_room')));
            $totalVa   = array_sum(array_column($smallCriticals, 'va'));
            $loadsArr  = array_map(fn($l) => [
                'name'     => $l['name'],
                'qty'      => $l['qty'],
                'va_each'  => $l['va_each'],
                'total_va' => $l['va'],
                'is_motor' => $l['is_motor'],
            ], $smallCriticals);
            $acc = ['total_va' => $totalVa, 'loads' => $loadsArr, 'room_names' => $roomNames, 'has_critical' => true];
            $c   = $this->finalizeCircuit($acc, 'CRITICAL', $rules);
            $c['rcd'] = ($rules['rcd_policy'] === '30mA_all') ? '30mA' : 'none';
            $allCircuits[] = $c;
        }

        // Guarantee ≥2 lighting circuits per floor
        $allCircuits = $this->ensureMinLightingCircuits($allCircuits, $rules, $needsRcd);

        // Floor-own components sit directly on the floor DB
        foreach ($this->buildDirectCircuits($floorOwnLoads, $rules) as $c) {
            $c['room_names'] = ['(Floor direct)'];
            $allCircuits[] = $c;
        }

        // Phase 1: split circuits that exceed the electrical (breaker) limit so
        // sub-circuits can land on different phases (IEC 60364-8-1 §6.3).
        $allCircuits = $this->splitOversizedCircuits($allCircuits, $rules);
        // Phase 3: iteratively re-split the heaviest-phase circuit until imbalance
        // drops below IMBALANCE_TARGET or no improving move exists.
        $allCircuits = $this->balanceDrivenReSplit($allCircuits, $rules);
        // Final assignment: pure LPT to ensure consistent phase totals; saved hints
        // are bypassed because balanceDrivenReSplit already ran pure LPT internally.
        $allCircuits = $this->assignPhases($allCircuits, false);
        foreach ($allCircuits as $i => &$c) { $c['circuit_no'] = $i + 1; }
        unset($c);

        $floorDivVA   = $roomDivVASum + $floorOwnVA;
        $incomerVA    = $floorDivVA * (1.0 + $rules['spare_capacity_pct'] / 100.0);
        $incomer      = $this->sizeIncomer($incomerVA, true, $rules);
        $phaseBalance = $this->computePhaseBalance($allCircuits);

        return [
            'id'   => $floor->id,
            'name' => $floor->name,
            'db'   => [
                'total_va_nameplate'   => round($nameplateSum, 1),
                'total_va_diversified' => round($floorDivVA, 1),
                'incomer_ib_a'         => $incomer['ib_a'],
                'incomer_in_a'         => $incomer['in_a'],
                'incomer_cable_mm2'    => $incomer['cable_mm2'],
                'incomer_pe_mm2'       => $incomer['pe_mm2'],
                'incomer_curve'        => 'C',
                'phase_balance_va'     => $phaseBalance,
                'phase_imbalance_pct'  => $this->imbalancePct($phaseBalance),
                'rcd_groups'           => $this->buildRcdGroups($allCircuits, $rules),
            ],
            'circuits' => array_values($allCircuits),
            'rooms'    => $roomSummaries,
        ];
    }

    // ── Component loading ──────────────────────────────────────────────────────

    private function extractLoads($components, array $rules): array
    {
        $groups    = [];
        $ungrouped = [];

        foreach ($components as $c) {
            $va = (float) $c->power * (int) $c->quantity;

            if ($c->group_name) {
                if (!isset($groups[$c->group_name]) || $va > $groups[$c->group_name]['va']) {
                    $groups[$c->group_name] = $this->formatLoad($c, $va, $rules);
                }
            } else {
                $ungrouped[] = $this->formatLoad($c, $va, $rules);
            }
        }

        return array_merge($ungrouped, array_values($groups));
    }

    private function formatLoad(object $component, float $va, array $rules): array
    {
        $isMotor  = (bool) ($component->componentType->is_motor ?? false);
        $is3ph    = ($component->phases === '3phase');
        $motorThreshold = $rules['motor_dedicated_threshold_va'] ?? 750;

        if ($is3ph || $va >= $rules['heavy_threshold_va'] || ($isMotor && $va >= $motorThreshold)) {
            $circuitType = 'HEAVY';
        } elseif (($component->priority ?? 'normal') === 'critical') {
            $circuitType = 'CRITICAL';
        } elseif ((bool) $component->needs_socket) {
            $circuitType = 'SOCKET';
        } elseif ($this->isLuminaire($component->componentType)) {
            $circuitType = 'LIGHTING';
        } else {
            $circuitType = 'AUXILIARY';
        }

        return [
            'name'         => $component->componentType->name ?? 'Load',
            'qty'          => (int) $component->quantity,
            'va'           => $va,
            'va_each'      => (float) $component->power,
            'is3ph'        => $is3ph,
            'is_motor'     => $isMotor,
            'priority'     => $component->priority ?? 'normal',
            'circuit_type' => $circuitType,
            'saved_phase'  => $component->phase ?? null,   // DB phase from PhaseBalanceController
        ];
    }

    // ── Circuit builders ───────────────────────────────────────────────────────

    private function buildSingleLoadCircuit(array $load, array $rules): array
    {
        $type     = $load['circuit_type'];
        $sizing   = $this->sizeCircuit($load['va'], $load['is3ph'], $type, $rules, $load['is_motor']);
        $needsRcd = ($rules['rcd_policy'] === '30mA_all')
                    || (in_array($rules['rcd_policy'], ['30mA_socket_lighting'])
                        && in_array($type, ['SOCKET', 'LIGHTING', 'MIXED']));

        return [
            'type'            => $type,
            'is3ph'           => $load['is3ph'],
            'room_names'      => [],
            'loads'           => [[
                'name'        => $load['name'],
                'qty'         => $load['qty'],
                'va_each'     => $load['va_each'],
                'total_va'    => $load['va'],
                'is_motor'    => $load['is_motor'],
                'saved_phase' => $load['saved_phase'] ?? null,
            ]],
            'total_va'        => $load['va'],
            'ib_a'            => $sizing['ib_a'],
            'in_a'            => $sizing['in_a'],
            'curve'           => $sizing['curve'],
            'cable_mm2'       => $sizing['cable_mm2'],
            'pe_mm2'          => $sizing['pe_mm2'],
            'rcd'             => $needsRcd ? '30mA' : 'none',
            'rcd_group'       => $this->rcdGroupForType($type),
            'utilisation_pct' => $this->utilisationPct($sizing['ib_a'], $sizing['in_a']),
            'mv_a_m'          => $this->mvAmForCable($sizing['cable_mm2']),
            'vd_limit_pct'    => $this->vdLimitPct($type),
            'has_critical'    => ($load['priority'] === 'critical'),
            'vd_note'         => 'length required',
            'phase'           => null,
        ];
    }

    private function packSocketCircuits(array $loads, string $roomName, array $rules, string $type = 'SOCKET'): array
    {
        $circuits = [];
        $capVA    = $rules['socket_breaker_a'] * self::V_PHASE * $rules['breaker_loading_factor'];
        $maxOut   = $rules['socket_outlets_per_circuit'];
        $needsRcd = in_array($rules['rcd_policy'], ['30mA_socket_lighting', '30mA_all']);

        $acc = null;

        foreach ($loads as $load) {
            $wouldExceedVA  = $acc && ($acc['total_va'] + $load['va'] > $capVA) && $acc['total_va'] > 0;
            $wouldExceedOut = $acc && ($acc['outlet_count'] + $load['qty'] > $maxOut);

            if (($wouldExceedVA || $wouldExceedOut) && $acc !== null) {
                $circuits[] = $this->finalizeCircuit($acc, $type, $rules);
                $acc = null;
            }

            if ($acc === null) {
                $acc = ['total_va' => 0.0, 'outlet_count' => 0, 'loads' => [],
                        'room_names' => [$roomName], 'has_critical' => false];
            }

            $acc['total_va']     += $load['va'];
            $acc['outlet_count'] += $load['qty'];
            $acc['loads'][]       = [
                'name'        => $load['name'],
                'qty'         => $load['qty'],
                'va_each'     => $load['va_each'],
                'total_va'    => $load['va'],
                'saved_phase' => $load['saved_phase'] ?? null,
            ];
            if ($load['priority'] === 'critical') $acc['has_critical'] = true;
        }

        if ($acc !== null) {
            $circuits[] = $this->finalizeCircuit($acc, $type, $rules);
        }

        foreach ($circuits as &$c) { $c['rcd'] = $needsRcd ? '30mA' : 'none'; }
        unset($c);

        return $circuits;
    }

    private function packLightingLoads(
        array  $loads,
        string $roomName,
        array  $allCircuits,
        ?array $openCircuit,
        array  $rules
    ): array {
        $capVA    = $rules['lighting_breaker_a'] * self::V_PHASE * $rules['breaker_loading_factor'];
        $needsRcd = in_array($rules['rcd_policy'], ['30mA_socket_lighting', '30mA_all']);

        foreach ($loads as $load) {
            $wouldExceed = $openCircuit
                && ($openCircuit['total_va'] + $load['va'] > $capVA)
                && $openCircuit['total_va'] > 0;

            if ($wouldExceed) {
                $c = $this->finalizeCircuit($openCircuit, 'LIGHTING', $rules);
                $c['rcd'] = $needsRcd ? '30mA' : 'none';
                $allCircuits[] = $c;
                $openCircuit   = null;
            }

            if ($openCircuit === null) {
                $openCircuit = ['total_va' => 0.0, 'loads' => [], 'room_names' => [], 'has_critical' => false];
            }

            if (!in_array($roomName, $openCircuit['room_names'])) {
                $openCircuit['room_names'][] = $roomName;
            }

            $openCircuit['total_va'] += $load['va'];
            $openCircuit['loads'][]   = [
                'name'        => $load['name'],
                'qty'         => $load['qty'],
                'va_each'     => $load['va_each'],
                'total_va'    => $load['va'],
                'room'        => $roomName,
                'saved_phase' => $load['saved_phase'] ?? null,
            ];
            if ($load['priority'] === 'critical') $openCircuit['has_critical'] = true;
        }

        return [$allCircuits, $openCircuit];
    }

    private function packAuxiliaryLoads(
        array  $loads,
        string $roomName,
        array  $allCircuits,
        ?array $openCircuit,
        array  $rules
    ): array {
        $capVA       = ($rules['auxiliary_breaker_a'] ?? 10) * self::V_PHASE * $rules['breaker_loading_factor'];
        $needsRcdAux = ($rules['rcd_policy'] === '30mA_all');

        foreach ($loads as $load) {
            $wouldExceed = $openCircuit
                && ($openCircuit['total_va'] + $load['va'] > $capVA)
                && $openCircuit['total_va'] > 0;

            if ($wouldExceed) {
                $c = $this->finalizeCircuit($openCircuit, 'AUXILIARY', $rules);
                $c['rcd'] = $needsRcdAux ? '30mA' : 'none';
                $allCircuits[] = $c;
                $openCircuit   = null;
            }

            if ($openCircuit === null) {
                $openCircuit = ['total_va' => 0.0, 'loads' => [], 'room_names' => [], 'has_critical' => false];
            }

            if (!in_array($roomName, $openCircuit['room_names'])) {
                $openCircuit['room_names'][] = $roomName;
            }

            $openCircuit['total_va'] += $load['va'];
            $openCircuit['loads'][]   = [
                'name'        => $load['name'],
                'qty'         => $load['qty'],
                'va_each'     => $load['va_each'],
                'total_va'    => $load['va'],
                'room'        => $roomName,
                'saved_phase' => $load['saved_phase'] ?? null,
            ];
            if ($load['priority'] === 'critical') $openCircuit['has_critical'] = true;
        }

        return [$allCircuits, $openCircuit];
    }

    private function finalizeCircuit(array $acc, string $type, array $rules): array
    {
        $sizing = $this->sizeCircuit($acc['total_va'], false, $type, $rules);

        return [
            'type'            => $type,
            'is3ph'           => false,
            'room_names'      => $acc['room_names'] ?? [],
            'loads'           => $acc['loads'],
            'total_va'        => round($acc['total_va'], 1),
            'ib_a'            => $sizing['ib_a'],
            'in_a'            => $sizing['in_a'],
            'curve'           => $sizing['curve'],
            'cable_mm2'       => $sizing['cable_mm2'],
            'pe_mm2'          => $sizing['pe_mm2'],
            'rcd'             => 'none',   // caller overrides
            'rcd_group'       => $this->rcdGroupForType($type),
            'utilisation_pct' => $this->utilisationPct($sizing['ib_a'], $sizing['in_a']),
            'mv_a_m'          => $this->mvAmForCable($sizing['cable_mm2']),
            'vd_limit_pct'    => $this->vdLimitPct($type),
            'has_critical'    => $acc['has_critical'] ?? false,
            'vd_note'         => 'length required',
            'phase'           => null,
        ];
    }

    /**
     * Route floor-direct / building-direct loads to the correct packer.
     * Respects group_small_critical rule for CRITICAL loads.
     */
    private function buildDirectCircuits(array $loads, array $rules): array
    {
        $circuits      = [];
        $needsRcd      = in_array($rules['rcd_policy'], ['30mA_socket_lighting', '30mA_all']);
        $needsRcdAux   = ($rules['rcd_policy'] === '30mA_all');
        $groupSmall    = $rules['group_small_critical'] ?? false;
        $motorThreshold= $rules['motor_dedicated_threshold_va'] ?? 750;

        $heavyLoads    = array_values(array_filter($loads, fn($l) => $l['circuit_type'] === 'HEAVY'));
        $criticalLoads = array_values(array_filter($loads, fn($l) => $l['circuit_type'] === 'CRITICAL'));
        $sockets       = array_values(array_filter($loads, fn($l) => $l['circuit_type'] === 'SOCKET'));
        $lights        = array_values(array_filter($loads, fn($l) => $l['circuit_type'] === 'LIGHTING'));
        $auxLoads      = array_values(array_filter($loads, fn($l) => $l['circuit_type'] === 'AUXILIARY'));

        foreach ($heavyLoads as $load) {
            $circuits[] = $this->buildSingleLoadCircuit($load, $rules);
        }

        if ($groupSmall) {
            $large = array_values(array_filter($criticalLoads, fn($l) => $l['va_each'] >= $motorThreshold));
            $small = array_values(array_filter($criticalLoads, fn($l) => $l['va_each'] < $motorThreshold));

            foreach ($large as $load) {
                $circuits[] = $this->buildSingleLoadCircuit($load, $rules);
            }

            if (!empty($small)) {
                $totalVa  = array_sum(array_column($small, 'va'));
                $loadsArr = array_map(fn($l) => [
                    'name'        => $l['name'], 'qty' => $l['qty'],
                    'va_each'     => $l['va_each'], 'total_va' => $l['va'], 'is_motor' => $l['is_motor'],
                    'saved_phase' => $l['saved_phase'] ?? null,
                ], $small);
                $acc = ['total_va' => $totalVa, 'loads' => $loadsArr,
                        'room_names' => ['(Direct)'], 'has_critical' => true];
                $c   = $this->finalizeCircuit($acc, 'CRITICAL', $rules);
                $c['rcd'] = ($rules['rcd_policy'] === '30mA_all') ? '30mA' : 'none';
                $circuits[] = $c;
            }
        } else {
            foreach ($criticalLoads as $load) {
                $circuits[] = $this->buildSingleLoadCircuit($load, $rules);
            }
        }

        foreach ($this->packSocketCircuits($sockets, '(Direct)', $rules) as $c) {
            $circuits[] = $c;
        }

        // Lighting packing
        $lightingCircuits = [];
        $openLight        = null;
        [$lightingCircuits, $openLight] = $this->packLightingLoads(
            $lights, '(Direct)', $lightingCircuits, $openLight, $rules
        );
        if ($openLight !== null) {
            $c = $this->finalizeCircuit($openLight, 'LIGHTING', $rules);
            $c['rcd'] = $needsRcd ? '30mA' : 'none';
            $lightingCircuits[] = $c;
        }

        // Auxiliary packing
        $auxCircuits = [];
        $openAux     = null;
        [$auxCircuits, $openAux] = $this->packAuxiliaryLoads(
            $auxLoads, '(Direct)', $auxCircuits, $openAux, $rules
        );
        if ($openAux !== null) {
            $c = $this->finalizeCircuit($openAux, 'AUXILIARY', $rules);
            $c['rcd'] = $needsRcdAux ? '30mA' : 'none';
            $auxCircuits[] = $c;
        }

        return array_merge($circuits, $lightingCircuits, $auxCircuits);
    }

    // ── Sizing calculations ────────────────────────────────────────────────────

    private function sizeCircuit(float $totalVa, bool $is3ph, string $type, array $rules, bool $isMotor = false): array
    {
        $ib = $this->computeIb($totalVa, $is3ph);

        $minBreaker = match ($type) {
            'LIGHTING'        => $rules['lighting_breaker_a'],
            'AUXILIARY'       => $rules['auxiliary_breaker_a'] ?? 10,
            'SOCKET', 'MIXED' => $rules['socket_breaker_a'],
            default           => 0,
        };
        $in = max($minBreaker, $this->nextBreakerSize($ib));

        $minCable = match ($type) {
            'LIGHTING', 'AUXILIARY' => 1.5,
            'SOCKET', 'MIXED'       => 2.5,
            default                 => 0.0,
        };
        $cable = max($minCable, $this->cableSizeForCurrentA($in));
        $pe    = $this->peSizeForCable($cable);
        $curve = $this->breakerCurve($type, $isMotor);

        return [
            'ib_a'      => round($ib, 2),
            'in_a'      => $in,
            'curve'     => $curve,
            'cable_mm2' => $cable,
            'pe_mm2'    => $pe,
        ];
    }

    private function computeIb(float $va, bool $is3ph): float
    {
        if ($va <= 0) return 0.0;
        return $is3ph
            ? $va / (self::SQRT3 * self::V_LINE)
            : $va / self::V_PHASE;
    }

    private function nextBreakerSize(float $ib): int
    {
        foreach (self::BREAKER_SIZES as $size) {
            if ($size >= $ib) return $size;
        }
        return (int) end(self::BREAKER_SIZES);
    }

    private function breakerCurve(string $type, bool $isMotor = false): string
    {
        if ($isMotor) return 'D';
        if ($type === 'LIGHTING') return 'B';
        return 'C';
    }

    private function cableSizeForCurrentA(float $in): float
    {
        foreach (self::CABLE_AMPACITY as $mm2 => $iz) {
            if ($iz * self::DERATING_40C >= $in) {
                return (float) $mm2;
            }
        }
        return (float) array_key_last(self::CABLE_AMPACITY);
    }

    private function peSizeForCable(float $s): float
    {
        if ($s <= 16.0) return $s;
        if ($s <= 35.0) return 16.0;
        return $s / 2.0;
    }

    // ── Utilisation ────────────────────────────────────────────────────────────

    private function utilisationPct(float $ib, int $in): int
    {
        if ($in <= 0) return 0;
        return (int) round($ib / $in * 100);
    }

    // ── Voltage drop ───────────────────────────────────────────────────────────

    /**
     * Look up the mV/A/m value for a cable cross-section.
     * Returns null if the size is not in the table.
     */
    private function mvAmForCable(float $cable_mm2): ?float
    {
        foreach (self::CABLE_VD_MV_A_M as $key => $mv) {
            if (abs((float) $key - $cable_mm2) < 0.01) {
                return $mv;
            }
        }
        return null;
    }

    /**
     * Voltage-drop limit for a circuit type (IEC 60364-8-1 Table 1):
     *   3 % for LIGHTING
     *   5 % for all other types
     */
    private function vdLimitPct(string $type): float
    {
        return $type === 'LIGHTING' ? 3.0 : 5.0;
    }

    /**
     * Compute voltage drop for a circuit.
     *
     * Formula (IEC mV/A/m method):
     *   ΔU (V)  = (mV/A/m) × Ib × length_m / 1000
     *   ΔU (%)  = ΔU / V_nominal × 100
     *             V_nominal = 230 (1-phase) or 400 (3-phase)
     *
     * Returns null when length_m is null or ≤ 0 (length not yet provided).
     * When ΔU% exceeds the type limit, vd_warn = true and vd_remedy_cable_mm2
     * is set to the smallest larger cable size that brings ΔU% within the limit.
     */
    private function computeVoltageDrop(
        float  $cable_mm2,
        float  $ib_a,
        ?float $length_m,
        bool   $is3ph,
        string $type
    ): ?array {
        if ($length_m === null || $length_m <= 0) return null;

        $mv = $this->mvAmForCable($cable_mm2);
        if ($mv === null) return null;

        $vd_v   = $mv * $ib_a * $length_m / 1000.0;
        $vd_pct = round($vd_v / ($is3ph ? self::V_LINE : self::V_PHASE) * 100.0, 2);
        $limit  = $this->vdLimitPct($type);
        $warn   = $vd_pct > $limit;

        $remedy = $warn ? $this->vdRemedyCable($cable_mm2, $ib_a, $length_m, $is3ph, $type) : null;

        return [
            'vd_v'                => round($vd_v, 3),
            'vd_pct'              => $vd_pct,
            'vd_limit_pct'        => $limit,
            'vd_warn'             => $warn,
            'vd_remedy_cable_mm2' => $remedy,
        ];
    }

    /**
     * Find the smallest cable cross-section larger than the current one that keeps
     * ΔU% within the circuit-type limit.  Returns null if none is found in the table.
     */
    private function vdRemedyCable(
        float  $cable_mm2,
        float  $ib_a,
        float  $length_m,
        bool   $is3ph,
        string $type
    ): ?float {
        $limit = $this->vdLimitPct($type);

        foreach (self::CABLE_VD_MV_A_M as $key => $mv) {
            $size = (float) $key;
            if ($size <= $cable_mm2) continue;

            $vd_v   = $mv * $ib_a * $length_m / 1000.0;
            $vd_pct = $vd_v / ($is3ph ? self::V_LINE : self::V_PHASE) * 100.0;
            if ($vd_pct <= $limit) {
                return $size;
            }
        }
        return null;
    }

    // ── RCD grouping ───────────────────────────────────────────────────────────

    private function rcdGroupForType(string $type): ?string
    {
        return match ($type) {
            'LIGHTING'        => 'RCCB-L',
            'SOCKET', 'MIXED' => 'RCCB-S',
            default           => null,
        };
    }

    private function buildRcdGroups(array $circuits, array $rules): array
    {
        $policy = $rules['rcd_policy'] ?? 'none';
        if (!in_array($policy, ['30mA_socket_lighting', '30mA_all'])) return [];

        $groups = [];
        if (!empty(array_filter($circuits, fn($c) => in_array($c['type'], ['SOCKET', 'MIXED'])))) {
            $groups[] = [
                'group'   => 'RCCB-S',
                'label'   => 'Socket group',
                'rccb_ma' => 30,
                'note'    => 'Shared 30 mA RCCB — plain MCBs downstream',
            ];
        }
        if (!empty(array_filter($circuits, fn($c) => $c['type'] === 'LIGHTING'))) {
            $groups[] = [
                'group'   => 'RCCB-L',
                'label'   => 'Lighting group',
                'rccb_ma' => 30,
                'note'    => 'Shared 30 mA RCCB — plain MCBs downstream',
            ];
        }
        return $groups;
    }

    // ── Room / luminaire detection ─────────────────────────────────────────────

    private function isDedicatedRoom(object $room): bool
    {
        if ($room->type !== null) {
            return in_array($room->type, self::DEDICATED_ROOM_TYPES, true);
        }
        $name = strtolower($room->name ?? '');
        foreach (self::DEDICATED_ROOM_NAME_KEYWORDS as $kw) {
            if (str_contains($name, $kw)) return true;
        }
        return false;
    }

    private function isLuminaire(?object $componentType): bool
    {
        if ($componentType === null) return false;
        $name = $componentType->name ?? '';
        if (in_array($name, self::LUMINAIRE_PRESET_NAMES, true)) return true;
        $lower = strtolower($name);
        foreach (self::LUMINAIRE_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    // ── Minimum lighting circuit count ────────────────────────────────────────

    private function ensureMinLightingCircuits(array $circuits, array $rules, bool $needsRcd): array
    {
        $lightIdxs = array_keys(array_filter($circuits, fn($c) => $c['type'] === 'LIGHTING'));
        if (count($lightIdxs) !== 1) return $circuits;

        $idx    = reset($lightIdxs);
        $single = $circuits[$idx];
        $loads  = $single['loads'] ?? [];
        if (count($loads) < 2) return $circuits;

        $half   = (int) ceil(count($loads) / 2);
        $loadsA = array_slice($loads, 0, $half);
        $loadsB = array_slice($loads, $half);

        $vaA = array_sum(array_column($loadsA, 'total_va'));
        $vaB = array_sum(array_column($loadsB, 'total_va'));

        $cA = $this->finalizeCircuit(
            ['total_va' => $vaA, 'loads' => $loadsA, 'room_names' => $single['room_names'], 'has_critical' => false],
            'LIGHTING', $rules
        );
        $cB = $this->finalizeCircuit(
            ['total_va' => $vaB, 'loads' => $loadsB, 'room_names' => $single['room_names'], 'has_critical' => false],
            'LIGHTING', $rules
        );
        $cA['rcd'] = $needsRcd ? '30mA' : 'none';
        $cB['rcd'] = $needsRcd ? '30mA' : 'none';

        array_splice($circuits, $idx, 1, [$cA, $cB]);
        return $circuits;
    }

    // ── Phase assignment ───────────────────────────────────────────────────────

    /**
     * Electrical-limit split: split any 1-phase circuit whose VA exceeds the
     * maximum a breaker can supply at the configured loading factor.
     *   max_circuit_VA = breaker_a × V_PHASE × LOADING_FACTOR
     *   n = ceil(circuit_VA / max_circuit_VA)   (no upper-bound cap)
     */
    private function splitOversizedCircuits(array $circuits, array $rules): array
    {
        $result = [];
        foreach ($circuits as $c) {
            $cVa   = (float) ($c['total_va'] ?? 0);
            $is3ph = (bool) ($c['is3ph'] ?? false);

            $totalFixtures = array_sum(
                array_map(fn($l) => max(1, (int) ($l['qty'] ?? 1)), $c['loads'] ?? [])
            );

            // Determine the electrical ceiling for this circuit type
            $breakerKey  = match (strtoupper($c['type'] ?? '')) {
                'LIGHTING'  => 'lighting_breaker_a',
                'SOCKET'    => 'socket_breaker_a',
                default     => 'auxiliary_breaker_a',
            };
            $breakerA    = (float) ($rules[$breakerKey] ?? 10);
            $loadFactor  = (float) ($rules['breaker_loading_factor'] ?? self::LOADING_FACTOR);
            $maxCircVA   = $breakerA * self::V_PHASE * $loadFactor;

            // Skip: 3-phase, within electrical limit, too small, or unsplittable
            if ($is3ph
                || $cVa <= $maxCircVA
                || $cVa < self::MIN_SPLIT_VA
                || $totalFixtures < 2)
            {
                $result[] = $c;
                continue;
            }

            $n = max(2, (int) ceil($cVa / $maxCircVA));

            foreach ($this->splitCircuitIntoN($c, $n, $rules) as $sub) {
                $result[] = $sub;
            }
        }

        return $result;
    }

    /**
     * Split a single 1-phase circuit into $n sub-circuits using LPT fixture
     * bin packing.  Returns an array of $n circuit arrays (fewer if some bins
     * end up empty after packing).
     */
    private function splitCircuitIntoN(array $c, int $n, array $rules): array
    {
        // Expand each load entry into individual fixture units
        $units = [];
        foreach ($c['loads'] as $load) {
            $qty    = max(1, (int) ($load['qty'] ?? 1));
            $vaEach = (float) ($load['va_each'] ?? 0);
            if ($vaEach <= 0 && $qty > 0) {
                $vaEach = ((float) ($load['total_va'] ?? 0)) / $qty;
            }
            for ($i = 0; $i < $qty; $i++) {
                $units[] = [
                    'name'        => $load['name'] ?? 'Load',
                    'va_each'     => $vaEach,
                    'is_motor'    => $load['is_motor'] ?? false,
                    'saved_phase' => $load['saved_phase'] ?? null,
                    'room'        => $load['room'] ?? null,
                    'priority'    => $load['priority'] ?? 'normal',
                ];
            }
        }

        // Sort descending so large units are placed first (LPT)
        usort($units, fn($a, $b) => $b['va_each'] <=> $a['va_each']);

        // Greedy N-way partition into lightest bin
        $bins  = array_fill(0, $n, []);
        $binVa = array_fill(0, $n, 0.0);
        foreach ($units as $unit) {
            $minIdx          = array_search(min($binVa), $binVa);
            $bins[$minIdx][] = $unit;
            $binVa[$minIdx] += $unit['va_each'];
        }

        $subs = [];
        foreach ($bins as $binUnits) {
            if (empty($binUnits)) continue;

            // Reconstruct load entries grouped by (name, va_each)
            $grouped = [];
            foreach ($binUnits as $unit) {
                $key = $unit['name'] . '|' . $unit['va_each'];
                if (! isset($grouped[$key])) {
                    $grouped[$key] = [
                        'name'        => $unit['name'],
                        'qty'         => 0,
                        'va_each'     => $unit['va_each'],
                        'total_va'    => 0.0,
                        'is_motor'    => $unit['is_motor'],
                        'saved_phase' => $unit['saved_phase'],
                    ];
                    if ($unit['room'] !== null) {
                        $grouped[$key]['room'] = $unit['room'];
                    }
                }
                $grouped[$key]['qty']++;
                $grouped[$key]['total_va'] = round(
                    $grouped[$key]['qty'] * $grouped[$key]['va_each'], 2
                );
            }

            $reconLoads = array_values($grouped);
            $sVA        = array_sum(array_column($reconLoads, 'total_va'));
            $sizing     = $this->sizeCircuit($sVA, false, $c['type'], $rules);
            $hasCrit    = (bool) ($c['has_critical'] ?? false)
                || ! empty(array_filter($reconLoads, fn($l) => ($l['priority'] ?? '') === 'critical'));

            $subs[] = [
                'type'            => $c['type'],
                'is3ph'           => false,
                'room_names'      => $c['room_names'] ?? [],
                'loads'           => $reconLoads,
                'total_va'        => round($sVA, 1),
                'ib_a'            => $sizing['ib_a'],
                'in_a'            => $sizing['in_a'],
                'curve'           => $sizing['curve'],
                'cable_mm2'       => $sizing['cable_mm2'],
                'pe_mm2'          => $sizing['pe_mm2'],
                'rcd'             => $c['rcd'],
                'rcd_group'       => $c['rcd_group'],
                'utilisation_pct' => $this->utilisationPct($sizing['ib_a'], $sizing['in_a']),
                'mv_a_m'          => $this->mvAmForCable($sizing['cable_mm2']),
                'vd_limit_pct'    => $c['vd_limit_pct'],
                'has_critical'    => $hasCrit,
                'vd_note'         => 'length required',
                'phase'           => null,
                'split_sub'       => true,
            ];
        }

        return $subs;
    }

    /**
     * Compute per-phase VA totals from an already-assigned circuit list.
     * Used by the balance-driven re-split loop.
     */
    private function phaseVAFromCircuits(array $circuits): array
    {
        $va = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        foreach ($circuits as $c) {
            if ($c['is3ph'] ?? false) {
                $each = (float) ($c['total_va'] ?? 0) / 3.0;
                $va['A'] += $each;
                $va['B'] += $each;
                $va['C'] += $each;
            } else {
                $ph = $c['phase'] ?? null;
                if ($ph && isset($va[$ph])) {
                    $va[$ph] += (float) ($c['total_va'] ?? 0);
                }
            }
        }
        return $va;
    }

    /**
     * Balance-driven re-split loop (Phase 3).
     *
     * After the electrical-limit split (Phase 1) and initial pure-LPT assignment,
     * repeatedly try to reduce imbalance by splitting the largest circuit on the
     * heaviest phase until either the target is met or no improving move exists.
     *
     * Uses pure LPT (no saved-phase hints) throughout so saved phases don't
     * prevent the greedy from finding the best distribution.
     */
    private function balanceDrivenReSplit(array $circuits, array $rules): array
    {
        for ($iter = 0; $iter < self::MAX_ITERS; $iter++) {
            $circuits  = $this->assignPhases($circuits, false);
            $phaseVA   = $this->phaseVAFromCircuits($circuits);
            $imbalance = $this->imbalancePct($phaseVA);

            if ($imbalance <= self::IMBALANCE_TARGET * 100.0) break;

            // Heaviest phase first
            arsort($phaseVA);
            $heaviest = array_key_first($phaseVA);

            // Find the largest splittable circuit on the heaviest phase
            $bestIdx = null;
            $bestVa  = 0.0;
            foreach ($circuits as $i => $c) {
                if ($c['_no_balance_split'] ?? false) continue;
                if ($c['is3ph'] ?? false) continue;
                if (($c['phase'] ?? null) !== $heaviest) continue;

                $cVa = (float) ($c['total_va'] ?? 0);
                // Each resulting half must be ≥ MIN_SPLIT_VA to avoid micro-circuits
                if ($cVa < 2.0 * self::MIN_SPLIT_VA) continue;

                $totalFixtures = array_sum(
                    array_map(fn($l) => max(1, (int) ($l['qty'] ?? 1)), $c['loads'] ?? [])
                );
                if ($totalFixtures < 2) continue;

                if ($cVa > $bestVa) {
                    $bestVa  = $cVa;
                    $bestIdx = $i;
                }
            }

            if ($bestIdx === null) break; // no candidate — local minimum

            // Try splitting the candidate into 2
            $splits    = $this->splitCircuitIntoN($circuits[$bestIdx], 2, $rules);
            $candidate = array_values(array_merge(
                array_slice($circuits, 0, $bestIdx),
                $splits,
                array_slice($circuits, $bestIdx + 1)
            ));
            $candidate    = $this->assignPhases($candidate, false);
            $newImbalance = $this->imbalancePct($this->phaseVAFromCircuits($candidate));

            if ($newImbalance < $imbalance) {
                $circuits = $candidate;
            } else {
                // Split did not improve — mark circuit ineligible for this run
                $circuits[$bestIdx]['_no_balance_split'] = true;
            }
        }

        return $circuits;
    }

    /**
     * Returns the phase whose loads account for ≥ 50 % of the circuit's total VA,
     * or null if no single phase dominates or no loads have a saved phase.
     * Used by assignPhases() to honour the phase-balance page's assignments.
     */
    private function dominantSavedPhase(array $loads): ?string
    {
        $vaByPhase = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $totalVa   = 0.0;

        foreach ($loads as $load) {
            $ph = $load['saved_phase'] ?? null;
            $va = (float) ($load['total_va'] ?? $load['va'] ?? 0);
            if ($ph !== null && isset($vaByPhase[$ph])) {
                $vaByPhase[$ph] += $va;
            }
            $totalVa += $va;
        }

        if ($totalVa <= 0) return null;
        $maxPh = array_keys($vaByPhase, max($vaByPhase))[0];
        return ($vaByPhase[$maxPh] / $totalVa >= 0.50) ? $maxPh : null;
    }

    private function assignPhases(array $circuits, bool $useSavedHints = true): array
    {
        if (empty($circuits)) return $circuits;

        $phaseVA = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        $indexed = [];
        foreach ($circuits as $i => $c) { $indexed[$i] = $c; }
        uasort($indexed, fn($a, $b) => ($b['total_va'] ?? 0) <=> ($a['total_va'] ?? 0));

        $result = $circuits;
        foreach ($indexed as $i => $c) {
            if ($c['is3ph'] ?? false) {
                $result[$i]['phase'] = '3PH';
                $each = (float) ($c['total_va'] ?? 0) / 3.0;
                $phaseVA['A'] += $each;
                $phaseVA['B'] += $each;
                $phaseVA['C'] += $each;
                continue;
            }

            // Honour the phase already saved in the DB (written by PhaseBalanceController
            // when the user applies the optimal phase balance). This keeps the panel
            // schedule consistent with the phase-balance page after "Apply Optimal".
            // Exception: circuits created by splitOversizedCircuits() must ignore the
            // saved phase hint — their loads may all share the same saved phase (e.g.
            // all room lights on 'A'), and we need the greedy to spread them freely.
            // When $useSavedHints=false (balance-driven re-split loop), skip hints
            // entirely so pure LPT can redistribute circuits freely.
            $savedPh = ($useSavedHints && ! ($c['split_sub'] ?? false))
                ? $this->dominantSavedPhase($c['loads'] ?? [])
                : null;
            if ($savedPh !== null) {
                $result[$i]['phase'] = $savedPh;
                $phaseVA[$savedPh]  += (float) ($c['total_va'] ?? 0);
                continue;
            }

            // Fallback: greedy — assign to the lightest phase so far.
            $ph = array_keys($phaseVA, min($phaseVA))[0];
            $result[$i]['phase'] = $ph;
            $phaseVA[$ph]       += (float) ($c['total_va'] ?? 0);
        }

        return $result;
    }

    private function computePhaseBalance(array $circuits): array
    {
        $va = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        foreach ($circuits as $c) {
            $ph = $c['phase'] ?? null;
            if ($ph === '3PH') {
                $each = (float) ($c['total_va'] ?? 0) / 3.0;
                $va['A'] += $each; $va['B'] += $each; $va['C'] += $each;
            } elseif ($ph && isset($va[$ph])) {
                $va[$ph] += (float) ($c['total_va'] ?? 0);
            }
        }
        return array_map(fn($v) => round($v, 1), $va);
    }

    private function imbalancePct(array $phaseVA): float
    {
        $vals = array_values($phaseVA);
        $avg  = array_sum($vals) / 3.0;
        if ($avg <= 0) return 0.0;
        return round((max($vals) - min($vals)) / $avg * 100.0, 1);
    }

    // ── Incomer / feeder helpers ───────────────────────────────────────────────

    private function sizeIncomer(float $va, bool $is3ph, array $rules): array
    {
        $sizing          = $this->sizeCircuit($va, $is3ph, 'SOCKET', $rules);
        $sizing['curve'] = 'C';
        return $sizing;
    }

    private function makeFloorFeeders(array $floorResults, array $rules): array
    {
        $feeders = [];
        foreach ($floorResults as $floor) {
            $divVA  = $floor['db']['total_va_diversified'];
            $sizing = $this->sizeCircuit($divVA, true, 'SOCKET', $rules);
            $feeders[] = [
                'type'            => 'FLOOR_FEEDER',
                'is3ph'           => true,
                'label'           => 'Feeder → ' . $floor['name'],
                'floor_id'        => $floor['id'],
                'floor_name'      => $floor['name'],
                'room_names'      => [],
                'loads'           => [[
                    'name'     => $floor['name'] . ' DB',
                    'qty'      => 1,
                    'va_each'  => $divVA,
                    'total_va' => $divVA,
                ]],
                'total_va'        => $divVA,
                'ib_a'            => $sizing['ib_a'],
                'in_a'            => $sizing['in_a'],
                'curve'           => 'C',
                'cable_mm2'       => $sizing['cable_mm2'],
                'pe_mm2'          => $sizing['pe_mm2'],
                'rcd'             => 'none',
                'rcd_group'       => null,
                'utilisation_pct' => $this->utilisationPct($sizing['ib_a'], $sizing['in_a']),
                'mv_a_m'          => $this->mvAmForCable($sizing['cable_mm2']),
                'vd_limit_pct'    => 5.0,
                'has_critical'    => false,
                'vd_note'         => 'length required',
                'phase'           => '3PH',
            ];
        }
        return $feeders;
    }

    private function extractEssentialPanel(array $floorResults, array $rules): ?array
    {
        $essentialCircuits = [];
        foreach ($floorResults as $floor) {
            foreach ($floor['circuits'] as $c) {
                if ($c['has_critical']) {
                    $essentialCircuits[] = array_merge($c, ['floor_name' => $floor['name']]);
                }
            }
        }
        if (empty($essentialCircuits)) return null;

        $totalVA   = array_sum(array_column($essentialCircuits, 'total_va'));
        $incomerVA = $totalVA * (1.0 + $rules['spare_capacity_pct'] / 100.0);
        $incomer   = $this->sizeIncomer($incomerVA, true, $rules);

        return [
            'note'              => 'Critical loads — feed via ATS-backed essential busbar',
            'total_va'          => round($totalVA, 1),
            'incomer_in_a'      => $incomer['in_a'],
            'incomer_cable_mm2' => $incomer['cable_mm2'],
            'incomer_pe_mm2'    => $incomer['pe_mm2'],
            'circuits'          => $essentialCircuits,
        ];
    }
}
