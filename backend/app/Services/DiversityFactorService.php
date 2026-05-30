<?php

namespace App\Services;

class DiversityFactorService
{
    // IEC 60364-8-1 / BS 7671 / CIBSE Guide C building-type diversity factors.
    // 'room_to_floor'     = DF applied when summing rooms up to their floor.
    // 'floor_to_building' = DF applied when summing floors up to their building.
    private const BUILDING_DFS = [
        'residential_house'      => ['room_to_floor' => 0.60, 'floor_to_building' => 0.70],
        'residential_apartment'  => ['room_to_floor' => 0.65, 'floor_to_building' => 0.70],
        'hotel'                  => ['room_to_floor' => 0.65, 'floor_to_building' => 0.70],
        'office'                 => ['room_to_floor' => 0.85, 'floor_to_building' => 0.80],
        'educational_school'     => ['room_to_floor' => 0.80, 'floor_to_building' => 0.80],
        'educational_university' => ['room_to_floor' => 0.85, 'floor_to_building' => 0.80],
        'retail'                 => ['room_to_floor' => 0.85, 'floor_to_building' => 0.85],
        'hospital'               => ['room_to_floor' => 0.90, 'floor_to_building' => 0.90],
        'industrial'             => ['room_to_floor' => 0.85, 'floor_to_building' => 0.85],
        'mosque_worship'         => ['room_to_floor' => 0.80, 'floor_to_building' => 0.75],
        'sports'                 => ['room_to_floor' => 0.80, 'floor_to_building' => 0.80],
    ];

    // Generic IEC 60364-8-1 defaults used when building type is not classified.
    private const DEFAULT_BUILDING_DFS = ['room_to_floor' => 0.90, 'floor_to_building' => 0.80];

    // Per-room coincidence factors (CIBSE / IEC usage demand factors).
    private const ROOM_DFS = [
        'server_room'         => 1.00,
        'operating_theater'   => 1.00,
        'laboratory'          => 0.90,
        'classroom'           => 0.85,
        'lecture_hall'        => 0.85,
        'workshop'            => 0.85,
        'retail_floor'        => 0.85,
        'office_open'         => 0.80,
        'gym_sports'          => 0.80,
        'kitchen_commercial'  => 0.75,
        'office_private'      => 0.75,
        'prayer_hall'         => 0.75,
        'reception_lobby'     => 0.70,
        'meeting_room'        => 0.70,
        'corridor'            => 0.60,
        'living_room'         => 0.60,
        'kitchen_residential' => 0.55,
        'hotel_room'          => 0.50,
        'bedroom'             => 0.45,
        'warehouse_storage'   => 0.30,
        'bathroom'            => 0.25,
    ];

    private const DEFAULT_ROOM_DF = 0.80;

    /** Returns ['room_to_floor', 'floor_to_building'] for the given building type (null → generic IEC). */
    public static function buildingDfs(?string $type): array
    {
        return self::BUILDING_DFS[$type] ?? self::DEFAULT_BUILDING_DFS;
    }

    /** Returns the room-level coincidence factor for the given room type (null → generic IEC). */
    public static function roomDf(?string $type): float
    {
        return self::ROOM_DFS[$type] ?? self::DEFAULT_ROOM_DF;
    }
}
