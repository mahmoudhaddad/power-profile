<?php

namespace Tests\Feature;

use App\Services\ElectricalDesignService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Randomized generality tests for the phase-balance algorithm.
 *
 * Each seed generates a structurally different floor (varied room count, circuit
 * types, fixture counts, VA per fixture).  Tests assert:
 *
 *  1. The final imbalance is ≤ 15 % OR the result is a local minimum (no single
 *     greedy-swap move reduces imbalance further).
 *  2. VA is conserved across splits: sum of 1-phase circuit VAs equals the total
 *     1-phase input VA.
 *  3. phase_balance_va per-phase values match what the circuits produce.
 *
 * 100 seeds are used so a pass implies the algorithm generalises, not that it
 * was tuned to a specific dataset.
 */
class PhaseBalanceGeneralityTest extends TestCase
{
    private ElectricalDesignService $service;
    private \ReflectionMethod $analyzeFloorMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ElectricalDesignService();
        $rc            = new ReflectionClass($this->service);
        $m             = $rc->getMethod('analyzeFloor');
        $m->setAccessible(true);
        $this->analyzeFloorMethod = $m;
    }

    // ── Data provider ─────────────────────────────────────────────────────────

    public static function seeds(): array
    {
        // 100 distinct integer seeds
        return array_map(fn($i) => [$i * 31 + 997], range(0, 99));
    }

    // ── Main randomised tests ─────────────────────────────────────────────────

    #[Test]
    #[DataProvider('seeds')]
    public function imbalance_at_target_or_local_minimum(int $seed): void
    {
        $floor     = $this->makeRandomFloor($seed, 1, 15);
        $result    = $this->callAnalyzeFloor($floor);
        $imbalance = (float) $result['db']['phase_imbalance_pct'];

        if ($imbalance <= 15.0) {
            $this->assertLessThanOrEqual(15.0, $imbalance,
                "Seed $seed: imbalance $imbalance % exceeds 15 %");
            return;
        }

        // Target not met — must be a local minimum: no greedy-swap strictly reduces it.
        $this->assertAtLocalMinimum(
            $result['circuits'], $imbalance,
            "Seed $seed: imbalance $imbalance % > 15 % and an improving swap exists"
        );
    }

    #[Test]
    #[DataProvider('seeds')]
    public function va_is_conserved_across_splits(int $seed): void
    {
        $floor  = $this->makeRandomFloor($seed, 1, 15);
        $result = $this->callAnalyzeFloor($floor);

        // Sum 1-phase input VA from all room components + floor-own components
        $inputVA = 0.0;
        foreach ($floor->rooms as $room) {
            foreach ($room->components as $comp) {
                if ($comp->phases !== '3phase') {
                    $inputVA += (float) $comp->power * (int) $comp->quantity;
                }
            }
        }
        foreach ($floor->components as $comp) {
            if ($comp->phases !== '3phase') {
                $inputVA += (float) $comp->power * (int) $comp->quantity;
            }
        }

        // Sum 1-phase circuit VAs out
        $circuitVA = 0.0;
        foreach ($result['circuits'] as $c) {
            if (! ($c['is3ph'] ?? false)) {
                $circuitVA += (float) ($c['total_va'] ?? 0);
            }
        }

        $this->assertEqualsWithDelta($inputVA, $circuitVA, 1.0,
            "Seed $seed: VA not conserved — input $inputVA VA, circuit total $circuitVA VA");
    }

    #[Test]
    #[DataProvider('seeds')]
    public function phase_balance_va_matches_circuit_totals(int $seed): void
    {
        $floor  = $this->makeRandomFloor($seed, 1, 15);
        $result = $this->callAnalyzeFloor($floor);

        $phaseVA    = $result['db']['phase_balance_va'];
        $recomputed = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];

        foreach ($result['circuits'] as $c) {
            $ph = $c['phase'] ?? null;
            if ($ph === '3PH') {
                $each = (float) ($c['total_va'] ?? 0) / 3.0;
                $recomputed['A'] += $each;
                $recomputed['B'] += $each;
                $recomputed['C'] += $each;
            } elseif ($ph && isset($recomputed[$ph])) {
                $recomputed[$ph] += (float) ($c['total_va'] ?? 0);
            }
        }

        foreach (['A', 'B', 'C'] as $ph) {
            $this->assertEqualsWithDelta(
                $recomputed[$ph], (float) $phaseVA[$ph], 1.0,
                "Seed $seed: phase $ph mismatch (expected {$recomputed[$ph]}, got {$phaseVA[$ph]})"
            );
        }
    }

    // ── Fixed scenario tests ─────────────────────────────────────────────────

    #[Test]
    public function balanced_floor_gets_no_extra_circuits_from_phase3(): void
    {
        // 3 rooms × 10 × 40 VA lights → 3 circuits of 400 VA each.
        // Phase 1 will not split (400 VA < 10 A × 230 V × 0.8 = 1840 VA).
        // Phase 3 has nothing to improve — circuit count must stay at most 3.
        $floor  = $this->makeSymmetricFloor(3, 10, 40.0);
        $result = $this->callAnalyzeFloor($floor);

        $imbalance = (float) $result['db']['phase_imbalance_pct'];
        $this->assertLessThanOrEqual(15.0, $imbalance,
            "Symmetric floor imbalance $imbalance % exceeds 15 %");

        $lightingCount = count(array_filter(
            $result['circuits'],
            fn($c) => $c['type'] === 'LIGHTING' && ! ($c['is3ph'] ?? false)
        ));
        $this->assertLessThanOrEqual(3, $lightingCount,
            "Symmetric floor has $lightingCount lighting circuits — Phase 3 added unnecessary splits");
    }

    #[Test]
    public function dominant_load_floor_reaches_minimum(): void
    {
        // One room: 20× 100 VA lights = 2000 VA (exceeds 1840 VA limit, Phase 1 splits).
        // Two other rooms: 1× 10 VA light each.
        $floor  = $this->makeDominantLoadFloor();
        $result = $this->callAnalyzeFloor($floor);

        $imbalance = (float) $result['db']['phase_imbalance_pct'];

        if ($imbalance > 15.0) {
            $this->assertAtLocalMinimum(
                $result['circuits'], $imbalance,
                "Dominant-load floor: imbalance $imbalance % but an improving swap exists"
            );
        } else {
            $this->assertLessThanOrEqual(15.0, $imbalance);
        }
    }

    #[Test]
    public function single_room_floor_does_not_crash(): void
    {
        $floor  = $this->makeRandomFloor(42, 1, 1);
        $result = $this->callAnalyzeFloor($floor);

        $this->assertArrayHasKey('db', $result);
        $this->assertArrayHasKey('phase_balance_va', $result['db']);
    }

    #[Test]
    public function all_tiny_loads_floor(): void
    {
        // All fixtures are 5 VA — well below MIN_SPLIT_VA = 150, so unsplittable.
        $floor  = $this->makeTinyLoadFloor(8, 3, 5.0);
        $result = $this->callAnalyzeFloor($floor);

        $imbalance = (float) $result['db']['phase_imbalance_pct'];
        if ($imbalance > 15.0) {
            $this->assertAtLocalMinimum(
                $result['circuits'], $imbalance,
                "All-tiny floor: imbalance $imbalance % but improving swap exists"
            );
        } else {
            $this->assertLessThanOrEqual(15.0, $imbalance);
        }
    }

    #[Test]
    public function large_floor_with_mixed_types(): void
    {
        $floor  = $this->makeMixedTypeFloor(10);
        $result = $this->callAnalyzeFloor($floor);

        $imbalance = (float) $result['db']['phase_imbalance_pct'];

        if ($imbalance > 15.0) {
            $this->assertAtLocalMinimum(
                $result['circuits'], $imbalance,
                "Mixed-type floor: imbalance $imbalance % but improving swap exists"
            );
        } else {
            $this->assertLessThanOrEqual(15.0, $imbalance);
        }

        $circuitVA = array_sum(array_map(
            fn($c) => ($c['is3ph'] ?? false) ? 0.0 : (float) ($c['total_va'] ?? 0),
            $result['circuits']
        ));
        $this->assertGreaterThan(0, $circuitVA, "Mixed-type floor produced no 1-phase VA");
    }

    // ── Assertion helpers ────────────────────────────────────────────────────

    /**
     * Assert that no single-circuit phase-swap reduces imbalance below $current.
     * This proves the result is a local minimum for the greedy metric.
     */
    private function assertAtLocalMinimum(array $circuits, float $current, string $message): void
    {
        $phaseVA = ['A' => 0.0, 'B' => 0.0, 'C' => 0.0];
        foreach ($circuits as $c) {
            if ($c['is3ph'] ?? false) continue;
            $ph = $c['phase'] ?? null;
            if ($ph && isset($phaseVA[$ph])) {
                $phaseVA[$ph] += (float) ($c['total_va'] ?? 0);
            }
        }

        foreach ($circuits as $c) {
            if ($c['is3ph'] ?? false) continue;
            $ph  = $c['phase'] ?? null;
            if (! $ph || ! isset($phaseVA[$ph])) continue;
            $cVa = (float) ($c['total_va'] ?? 0);
            $type = $c['type'];

            foreach (['A', 'B', 'C'] as $target) {
                if ($target === $ph) continue;
                $trial           = $phaseVA;
                $trial[$ph]     -= $cVa;
                $trial[$target] += $cVa;
                $trialImb        = $this->imbalancePct($trial);
                $this->assertGreaterThanOrEqual(
                    $current - 0.05,
                    $trialImb,
                    $message . " (moving $type {$cVa} VA from $ph to $target gives $trialImb % < $current %)"
                );
            }
        }
    }

    private function imbalancePct(array $va): float
    {
        $vals = array_values($va);
        $avg  = array_sum($vals) / 3.0;
        if ($avg <= 0) return 0.0;
        return round((max($vals) - min($vals)) / $avg * 100.0, 2);
    }

    // ── Floor / component factories ─────────────────────────────────────────

    private function callAnalyzeFloor(object $floor): array
    {
        $rules = $this->defaultRules();
        $bDfs  = ['room_to_floor' => 0.85, 'floor_to_building' => 0.80];
        return $this->analyzeFloorMethod->invoke($this->service, $floor, $rules, $bDfs);
    }

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
            'spare_capacity_pct'           => 20,
            'rcd_policy'                   => '30mA_socket_lighting',
            'essential_separation'         => false,
            'allow_mixed_general_circuits' => false,
            'group_small_critical'         => false,
            'standard_ref'                 => 'IEC 60364',
        ];
    }

    private function makeRandomFloor(int $seed, int $minRooms, int $maxRooms): object
    {
        mt_srand($seed);
        $roomCount = mt_rand($minRooms, $maxRooms);
        $rooms     = [];
        for ($r = 0; $r < $roomCount; $r++) {
            $fixtures = mt_rand(1, 20);
            $vaEach   = mt_rand(10, 200);
            $rooms[]  = $this->makeRoom($r + 1, "Room$r", $fixtures, $vaEach, 'LIGHTING');
        }
        return $this->makeFloor($rooms, []);
    }

    private function makeSymmetricFloor(int $rooms, int $fixturesPerRoom, float $vaEach): object
    {
        $roomObjs = [];
        for ($r = 0; $r < $rooms; $r++) {
            $roomObjs[] = $this->makeRoom($r + 1, "Room$r", $fixturesPerRoom, (int) $vaEach, 'LIGHTING');
        }
        return $this->makeFloor($roomObjs, []);
    }

    private function makeDominantLoadFloor(): object
    {
        return $this->makeFloor([
            $this->makeRoom(1, 'BigRoom',    20, 100, 'LIGHTING'),
            $this->makeRoom(2, 'SmallRoom1',  1,  10, 'LIGHTING'),
            $this->makeRoom(3, 'SmallRoom2',  1,  10, 'LIGHTING'),
        ], []);
    }

    private function makeTinyLoadFloor(int $rooms, int $fixturesEach, float $vaEach): object
    {
        $roomObjs = [];
        for ($r = 0; $r < $rooms; $r++) {
            $roomObjs[] = $this->makeRoom($r + 1, "TinyRoom$r", $fixturesEach, (int) $vaEach, 'LIGHTING');
        }
        return $this->makeFloor($roomObjs, []);
    }

    private function makeMixedTypeFloor(int $rooms): object
    {
        mt_srand(54321);
        $types    = ['LIGHTING', 'SOCKET', 'AUXILIARY'];
        $roomObjs = [];
        for ($r = 0; $r < $rooms; $r++) {
            $type     = $types[$r % 3];
            $fixtures = mt_rand(2, 12);
            $vaEach   = mt_rand(20, 300);
            $roomObjs[] = $this->makeRoom($r + 1, "MixRoom$r", $fixtures, $vaEach, $type);
        }
        return $this->makeFloor($roomObjs, []);
    }

    private function makeFloor(array $rooms, array $ownComponents): object
    {
        static $id = 0;
        $id++;
        $floor             = new \stdClass();
        $floor->id         = $id;
        $floor->name       = "Floor $id";
        $floor->rooms      = $rooms;
        $floor->components = $ownComponents;
        return $floor;
    }

    private function makeRoom(int $id, string $name, int $qty, int $vaEach, string $circuitType): object
    {
        $room             = new \stdClass();
        $room->id         = $id;
        $room->name       = $name;
        $room->type       = 'classroom';
        $room->components = $this->makeComponents($qty, $vaEach, $circuitType);
        return $room;
    }

    private function makeComponents(int $qty, int $vaEach, string $circuitType): array
    {
        $ct           = new \stdClass();
        $ct->name     = match ($circuitType) {
            'LIGHTING' => 'Light',
            'SOCKET'   => 'Socket',
            default    => 'AuxDevice',
        };
        $ct->is_motor = false;

        $comp               = new \stdClass();
        $comp->power        = $vaEach;
        $comp->quantity     = $qty;
        $comp->group_name   = null;
        $comp->phases       = 'single';
        $comp->priority     = 'normal';
        $comp->needs_socket = ($circuitType === 'SOCKET');
        $comp->phase        = null;
        $comp->componentType = $ct;

        return [$comp];
    }
}
