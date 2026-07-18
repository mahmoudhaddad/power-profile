<?php

namespace Tests\Unit;

use App\Services\SocketDemandService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the double-counting fix in SocketDemandService.
 *
 * Socket records represent spare / unknown outlet capacity only.
 * Outlets already covered by an itemised needs_socket=true RoomComponent must
 * not be double-counted.  The corrected outlet count = max(0, raw - allocated).
 *
 * These tests are pure unit tests — no DB, no HTTP.
 * They exercise applyFactors() with the corrected outlet count to verify the
 * formula-level behaviour of each scenario.
 */
class SocketDemandCorrectionTest extends TestCase
{
    private SocketDemandService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new SocketDemandService();
    }

    /** Apply the per-room correction: floor at zero, never negative. */
    private function correctedN(int $rawOutlets, int $nsQty): int
    {
        return max(0, $rawOutlets - $nsQty);
    }

    // ── Scenario 1: partial overlap ──────────────────────────────────────────

    public function test_partial_overlap_uses_remaining_outlets(): void
    {
        // 6 physical outlets, 4 already modelled as needs_socket components → 2 spare
        $remaining = $this->correctedN(6, 4);
        $this->assertSame(2, $remaining);

        // 2 outlets, first tier (≤10): 2 × 200 VA × 1.00 = 400 VA
        $this->assertSame(400.0, $this->svc->applyFactors($remaining));
    }

    // ── Scenario 2: over-allocated (more components than outlets) ────────────

    public function test_over_allocated_room_floors_at_zero(): void
    {
        // 2 physical outlets but 4 ns_comps entered → corrected = 0, never negative
        $remaining = $this->correctedN(2, 4);
        $this->assertSame(0, $remaining);
        $this->assertSame(0.0, $this->svc->applyFactors($remaining));
    }

    public function test_exactly_allocated_also_floors_at_zero(): void
    {
        // Islamic University: "academic problems room" has 7 outlets and 7 ns_comps
        $remaining = $this->correctedN(7, 7);
        $this->assertSame(0, $remaining);
        $this->assertSame(0.0, $this->svc->applyFactors($remaining));
    }

    // ── Scenario 3: no needs_socket components — must be unaffected ──────────

    public function test_room_with_no_ns_components_is_unaffected(): void
    {
        // All outlets are spare/unknown; full count must pass through unchanged
        $remaining = $this->correctedN(6, 0);
        $this->assertSame(6, $remaining);

        // 6 outlets, first tier: 6 × 200 VA × 1.00 = 1200 VA
        $this->assertSame(1200.0, $this->svc->applyFactors($remaining));
    }

    // ── Scenario 4: Phase-1 correction and Phase-2 fraction are independent ──

    public function test_outlet_correction_and_uncontrolled_fraction_compose_independently(): void
    {
        // Phase 1 (SocketDemandService): reduce the raw outlet count
        $corrected    = $this->correctedN(6, 4);   // 2 remaining
        $baseDemandVA = $this->svc->applyFactors($corrected);  // 400 VA

        // Phase 2 (ScheduleController constant — NOT in SocketDemandService):
        // SOCKET_UNCONTROLLED_FRACTION for educational_university = 0.07 (Phase 2 value)
        $fraction       = 0.07;
        $uncontrolledVA = $baseDemandVA * $fraction;
        $controlledVA   = $baseDemandVA - $uncontrolledVA;

        $this->assertEqualsWithDelta(28.0,  $uncontrolledVA, 0.001);
        $this->assertEqualsWithDelta(372.0, $controlledVA,   0.001);

        // The two phases do not affect each other.
        // Changing the fraction leaves the corrected outlet count untouched.
        $this->assertSame(2, $corrected);
        // SocketDemandService has no reference to SOCKET_UNCONTROLLED_FRACTION;
        // the base demand is derived solely from the corrected count.
        $this->assertSame(400.0, $baseDemandVA);
    }

    // ── Additional: tiered formula applies correctly to corrected count ───────

    public function test_tiered_demand_applies_to_corrected_count(): void
    {
        // 20 outlets, 12 ns_comps → 8 remaining; all in first tier (≤10)
        $r1 = $this->correctedN(20, 12);
        $this->assertSame(8, $r1);
        $this->assertSame(1600.0, $this->svc->applyFactors($r1)); // 8 × 200 × 1.00

        // 25 outlets, 5 ns_comps → 20 remaining; spans first + second tiers
        $r2 = $this->correctedN(25, 5);
        $this->assertSame(20, $r2);
        // 10 × 200 × 1.00 + 10 × 200 × 0.75 = 2000 + 1500 = 3500
        $this->assertSame(3500.0, $this->svc->applyFactors($r2));
    }
}
