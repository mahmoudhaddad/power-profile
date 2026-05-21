<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Project;
use App\Models\Room;
use App\Models\Socket;
use Illuminate\Support\Facades\DB;

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

    public function floorResult(Floor $floor): array
    {
        $roomN = (int) Socket::where('socketable_type', Room::class)
            ->whereIn('socketable_id', Room::where('floor_id', $floor->id)->pluck('id'))
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
    // Bulk-queries all floors at once instead of one query-pair per floor.

    public function buildingResult(Building $building): array
    {
        $floorIds = $building->floors()->pluck('id')->all();

        if (empty($floorIds)) {
            $ownN        = (int) $building->sockets()->sum('quantity');
            $ownDemandVA = $this->applyFactors($ownN);
            $cf          = $this->coincidenceFactor($ownDemandVA);
            return [
                'outlets'             => $ownN,
                'sum_floor_demand_va' => 0.0,
                'coincidence_factor'  => $cf,
                'demand_va'           => round($ownDemandVA * $cf, 2),
                'connected_va'        => round($ownN * self::OUTLET_VA, 2),
            ];
        }

        // Room sockets summed by floor_id (1 query)
        $roomSocketsByFloor = DB::table('sockets')
            ->join('rooms', 'sockets.socketable_id', '=', 'rooms.id')
            ->where('sockets.socketable_type', Room::class)
            ->whereIn('rooms.floor_id', $floorIds)
            ->groupBy('rooms.floor_id')
            ->selectRaw('rooms.floor_id, SUM(sockets.quantity) as total')
            ->pluck('total', 'floor_id')
            ->all();

        // Floor own sockets summed by floor (1 query)
        $floorOwnSockets = DB::table('sockets')
            ->where('socketable_type', Floor::class)
            ->whereIn('socketable_id', $floorIds)
            ->groupBy('socketable_id')
            ->selectRaw('socketable_id, SUM(quantity) as total')
            ->pluck('total', 'socketable_id')
            ->all();

        $floorDemandVA    = 0.0;
        $floorConnectedVA = 0.0;
        foreach ($floorIds as $fid) {
            $n = (int) ($roomSocketsByFloor[$fid] ?? 0) + (int) ($floorOwnSockets[$fid] ?? 0);
            $floorDemandVA    += $this->applyFactors($n);
            $floorConnectedVA += $n * self::OUTLET_VA;
        }

        // Building own sockets (1 query)
        $bldgOwnN    = (int) $building->sockets()->sum('quantity');
        $ownDemandVA = $this->applyFactors($bldgOwnN);

        $rawDemandVA = $floorDemandVA + $ownDemandVA;
        $cf          = $this->coincidenceFactor($rawDemandVA);

        return [
            'outlets'             => $bldgOwnN,
            'sum_floor_demand_va' => round($floorDemandVA, 2),
            'coincidence_factor'  => $cf,
            'demand_va'           => round($rawDemandVA * $cf, 2),
            'connected_va'        => round($floorConnectedVA + $bldgOwnN * self::OUTLET_VA, 2),
        ];
    }

    // ── Project ───────────────────────────────────────────────────────────────
    // Bulk-queries all buildings/floors at once: ~6 queries regardless of size.

    public function projectResult(Project $project): array
    {
        $buildingIds = $project->buildings()->pluck('id')->all();

        if (empty($buildingIds)) {
            $ownN        = (int) $project->sockets()->sum('quantity');
            $ownDemandVA = $this->applyFactors($ownN);
            return [
                'demand_va'    => round($ownDemandVA, 2),
                'connected_va' => round($ownN * self::OUTLET_VA, 2),
            ];
        }

        // All floors for all buildings (1 query)
        $floors           = Floor::whereIn('building_id', $buildingIds)->select('id', 'building_id')->get();
        $floorIds         = $floors->pluck('id')->all();
        $floorsByBuilding = $floors->groupBy('building_id');

        // Room sockets summed by floor_id (1 query)
        $roomSocketsByFloor = [];
        if (!empty($floorIds)) {
            $roomSocketsByFloor = DB::table('sockets')
                ->join('rooms', 'sockets.socketable_id', '=', 'rooms.id')
                ->where('sockets.socketable_type', Room::class)
                ->whereIn('rooms.floor_id', $floorIds)
                ->groupBy('rooms.floor_id')
                ->selectRaw('rooms.floor_id, SUM(sockets.quantity) as total')
                ->pluck('total', 'floor_id')
                ->all();
        }

        // Floor own sockets summed by floor (1 query)
        $floorOwnSockets = [];
        if (!empty($floorIds)) {
            $floorOwnSockets = DB::table('sockets')
                ->where('socketable_type', Floor::class)
                ->whereIn('socketable_id', $floorIds)
                ->groupBy('socketable_id')
                ->selectRaw('socketable_id, SUM(quantity) as total')
                ->pluck('total', 'socketable_id')
                ->all();
        }

        // Building own sockets (1 query)
        $bldgOwnSockets = DB::table('sockets')
            ->where('socketable_type', Building::class)
            ->whereIn('socketable_id', $buildingIds)
            ->groupBy('socketable_id')
            ->selectRaw('socketable_id, SUM(quantity) as total')
            ->pluck('total', 'socketable_id')
            ->all();

        $bldgDemandVA    = 0.0;
        $bldgConnectedVA = 0.0;
        foreach ($buildingIds as $bid) {
            $floorsForBldg = ($floorsByBuilding[$bid] ?? collect())->pluck('id')->all();

            $floorDemandVA    = 0.0;
            $floorConnectedVA = 0.0;
            foreach ($floorsForBldg as $fid) {
                $n = (int) ($roomSocketsByFloor[$fid] ?? 0) + (int) ($floorOwnSockets[$fid] ?? 0);
                $floorDemandVA    += $this->applyFactors($n);
                $floorConnectedVA += $n * self::OUTLET_VA;
            }

            $bldgOwnN    = (int) ($bldgOwnSockets[$bid] ?? 0);
            $ownDemandVA = $this->applyFactors($bldgOwnN);
            $rawDemand   = $floorDemandVA + $ownDemandVA;
            $cf          = $this->coincidenceFactor($rawDemand);

            $bldgDemandVA    += $rawDemand * $cf;
            $bldgConnectedVA += $floorConnectedVA + $bldgOwnN * self::OUTLET_VA;
        }

        // Project own sockets (1 query)
        $ownN        = (int) $project->sockets()->sum('quantity');
        $ownDemandVA = $this->applyFactors($ownN);

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
        if ($kva < 50)   return 1.00;
        if ($kva <= 250) return 0.92;
        return 0.85;
    }
}
