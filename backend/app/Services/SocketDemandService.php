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
        // Outlets already covered by an itemised needs_socket component must not be
        // double-counted. Socket records should represent spare / unknown capacity only
        // — not outlets you have already entered as specific RoomComponents.
        $allocated = (int) $room->components()->where('needs_socket', true)->sum('quantity');
        $n = max(0, $n - $allocated);
        return [
            'outlets'      => $n,
            'connected_va' => $n * self::OUTLET_VA,
            'demand_va'    => round($this->applyFactors($n), 2),
        ];
    }

    // ── Floor (primary demand level) ────────────────────────────────────────

    public function floorResult(Floor $floor): array
    {
        // Per-room socket qty minus needs_socket allocation, floored at zero per room.
        $roomIds = Room::where('floor_id', $floor->id)->pluck('id')->all();

        $socketByRoom = DB::table('sockets')
            ->where('socketable_type', Room::class)
            ->whereIn('socketable_id', $roomIds)
            ->groupBy('socketable_id')
            ->selectRaw('socketable_id as room_id, SUM(quantity) as total')
            ->pluck('total', 'room_id')->all();

        $allocByRoom = DB::table('room_components')
            ->whereIn('room_id', $roomIds)
            ->where('needs_socket', true)
            ->groupBy('room_id')
            ->selectRaw('room_id, SUM(quantity) as total')
            ->pluck('total', 'room_id')->all();

        $roomN = 0;
        foreach ($roomIds as $rid) {
            $roomN += max(0, (int)($socketByRoom[$rid] ?? 0) - (int)($allocByRoom[$rid] ?? 0));
        }

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

        // Per-room socket qty minus needs_socket allocation: 2 queries → corrected per-floor map.
        $roomFloorMap = DB::table('rooms')
            ->whereIn('floor_id', $floorIds)
            ->pluck('floor_id', 'id')->all();
        $allRoomIds = array_keys($roomFloorMap);

        $socketByRoom = [];
        $allocByRoom  = [];
        if (!empty($allRoomIds)) {
            $socketByRoom = DB::table('sockets')
                ->where('socketable_type', Room::class)
                ->whereIn('socketable_id', $allRoomIds)
                ->groupBy('socketable_id')
                ->selectRaw('socketable_id as room_id, SUM(quantity) as total')
                ->pluck('total', 'room_id')->all();

            $allocByRoom = DB::table('room_components')
                ->whereIn('room_id', $allRoomIds)
                ->where('needs_socket', true)
                ->groupBy('room_id')
                ->selectRaw('room_id, SUM(quantity) as total')
                ->pluck('total', 'room_id')->all();
        }

        // Corrected socket outlets per floor: per-room floor-at-zero, then sum per floor.
        $roomSocketsByFloor = [];
        foreach ($allRoomIds as $rid) {
            $fid = $roomFloorMap[$rid];
            $raw = (int)($socketByRoom[$rid] ?? 0);
            $ns  = (int)($allocByRoom[$rid]  ?? 0);
            $roomSocketsByFloor[$fid] = ($roomSocketsByFloor[$fid] ?? 0) + max(0, $raw - $ns);
        }

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
    // Bulk-queries all buildings/floors at once: ~8 queries regardless of size.

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

        // Per-room socket qty minus needs_socket allocation (2 queries), then aggregate by floor.
        $roomSocketsByFloor = [];
        if (!empty($floorIds)) {
            $roomFloorMap = DB::table('rooms')
                ->whereIn('floor_id', $floorIds)
                ->pluck('floor_id', 'id')->all();
            $allRoomIds = array_keys($roomFloorMap);

            $socketByRoom = [];
            $allocByRoom  = [];
            if (!empty($allRoomIds)) {
                $socketByRoom = DB::table('sockets')
                    ->where('socketable_type', Room::class)
                    ->whereIn('socketable_id', $allRoomIds)
                    ->groupBy('socketable_id')
                    ->selectRaw('socketable_id as room_id, SUM(quantity) as total')
                    ->pluck('total', 'room_id')->all();

                $allocByRoom = DB::table('room_components')
                    ->whereIn('room_id', $allRoomIds)
                    ->where('needs_socket', true)
                    ->groupBy('room_id')
                    ->selectRaw('room_id, SUM(quantity) as total')
                    ->pluck('total', 'room_id')->all();
            }

            // Per-room floor-at-zero, then sum per floor.
            foreach ($allRoomIds as $rid) {
                $fid = $roomFloorMap[$rid];
                $raw = (int)($socketByRoom[$rid] ?? 0);
                $ns  = (int)($allocByRoom[$rid]  ?? 0);
                $roomSocketsByFloor[$fid] = ($roomSocketsByFloor[$fid] ?? 0) + max(0, $raw - $ns);
            }
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

        // Building names for attribution labels (1 query)
        $buildingNames = Building::whereIn('id', $buildingIds)->select('id', 'name')->get()->keyBy('id');

        $bldgDemandVA       = 0.0;
        $bldgConnectedVA    = 0.0;
        $buildingsBreakdown = [];
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

            $buildingsBreakdown[] = [
                'name'         => $buildingNames->get($bid)?->name ?? "building-{$bid}",
                'demand_va'    => round($rawDemand * $cf, 2),
                'connected_va' => round($floorConnectedVA + $bldgOwnN * self::OUTLET_VA, 2),
            ];
        }

        // Project own sockets (1 query)
        $ownN        = (int) $project->sockets()->sum('quantity');
        $ownDemandVA = $this->applyFactors($ownN);

        return [
            'demand_va'           => round($bldgDemandVA + $ownDemandVA, 2),
            'connected_va'        => round($bldgConnectedVA + $ownN * self::OUTLET_VA, 2),
            'buildings_breakdown' => count($buildingsBreakdown) > 1 ? $buildingsBreakdown : [],
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
