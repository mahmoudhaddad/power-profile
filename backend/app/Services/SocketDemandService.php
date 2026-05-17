<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Project;
use App\Models\Room;
use App\Models\Socket;

class SocketDemandService
{
    const OUTLET_VA = 200;

    /**
     * First 10 outlets → 100 %, next 10 → 75 %, rest → 40 %
     */
    public function applyFactors(int $n): float
    {
        return min($n, 10)              * self::OUTLET_VA * 1.00
             + min(max($n - 10, 0), 10) * self::OUTLET_VA * 0.75
             + max($n - 20, 0)          * self::OUTLET_VA * 0.40;
    }

    // ── Room ────────────────────────────────────────────────────────────────

    public function roomResult(Room $room): array
    {
        $n = (int) $room->sockets()->sum('quantity');
        return [
            'outlets'      => $n,
            'connected_va' => $n * self::OUTLET_VA,
            'demand_va'    => round($this->applyFactors($n), 2),
        ];
    }

    // ── Floor (primary demand level) ────────────────────────────────────────
    // All room outlets + floor-level outlets are pooled, then factored together.

    public function floorResult(Floor $floor): array
    {
        $roomN = (int) Socket::where('socketable_type', Room::class)
            ->whereHas('socketable', fn($q) => $q->where('floor_id', $floor->id))
            ->sum('quantity');

        $ownN = (int) $floor->sockets()->sum('quantity');
        $n    = $roomN + $ownN;

        return [
            'outlets'      => $n,
            'connected_va' => $n * self::OUTLET_VA,
            'demand_va'    => round($this->applyFactors($n), 2),
        ];
    }

    // ── Building ─────────────────────────────────────────────────────────────
    // Sum diversified floor demands, add building-own-outlet demand, then
    // apply a coincidence factor based on total kVA size.

    public function buildingResult(Building $building): array
    {
        $floorDemandVA    = 0.0;
        $floorConnectedVA = 0.0;
        foreach ($building->floors()->get() as $f) {
            $fr = $this->floorResult($f);
            $floorDemandVA    += $fr['demand_va'];
            $floorConnectedVA += $fr['connected_va'];
        }

        $ownN        = (int) $building->sockets()->sum('quantity');
        $ownDemandVA = $this->applyFactors($ownN);

        $rawDemandVA = $floorDemandVA + $ownDemandVA;
        $cf          = $this->coincidenceFactor($rawDemandVA);

        return [
            'outlets'             => $ownN,
            'sum_floor_demand_va' => round($floorDemandVA, 2),
            'coincidence_factor'  => $cf,
            'demand_va'           => round($rawDemandVA * $cf, 2),
            'connected_va'        => round($floorConnectedVA + $ownN * self::OUTLET_VA, 2),
        ];
    }

    // ── Project ───────────────────────────────────────────────────────────────

    public function projectResult(Project $project): array
    {
        $bldgDemandVA    = 0.0;
        $bldgConnectedVA = 0.0;
        foreach ($project->buildings()->get() as $b) {
            $br = $this->buildingResult($b);
            $bldgDemandVA    += $br['demand_va'];
            $bldgConnectedVA += $br['connected_va'];
        }

        $ownN            = (int) $project->sockets()->sum('quantity');
        $ownDemandVA     = $this->applyFactors($ownN);

        return [
            'demand_va'    => round($bldgDemandVA + $ownDemandVA, 2),
            'connected_va' => round($bldgConnectedVA + $ownN * self::OUTLET_VA, 2),
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Small  < 50 kVA  → 1.00
     * Medium 50–250 kVA → 0.92  (midpoint of 0.90–0.95)
     * Large  250–1000 kVA → 0.85 (midpoint of 0.80–0.90)
     */
    private function coincidenceFactor(float $demandVA): float
    {
        $kva = $demandVA / 1000;
        if ($kva < 50)  return 1.00;
        if ($kva <= 250) return 0.92;
        return 0.85;
    }
}
