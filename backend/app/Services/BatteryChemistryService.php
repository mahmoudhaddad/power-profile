<?php

namespace App\Services;

class BatteryChemistryService
{
    private static array $presets = [
        'lead_acid_flooded' => [
            'label'                 => 'Lead-Acid (Flooded)',
            'depth_of_discharge'    => 0.50,
            'round_trip_efficiency' => 0.80,
            'c_rate_charge'         => 0.10,
            'c_rate_discharge'      => 0.20,
            'rated_cycle_life'      => 500,
            'calendar_life_years'   => 5,
            'degradation_per_year'  => 0.05,
        ],
        'lead_acid_agm' => [
            'label'                 => 'Lead-Acid (AGM)',
            'depth_of_discharge'    => 0.50,
            'round_trip_efficiency' => 0.85,
            'c_rate_charge'         => 0.20,
            'c_rate_discharge'      => 0.30,
            'rated_cycle_life'      => 700,
            'calendar_life_years'   => 7,
            'degradation_per_year'  => 0.04,
        ],
        'lead_acid_gel' => [
            'label'                 => 'Lead-Acid (Gel)',
            'depth_of_discharge'    => 0.50,
            'round_trip_efficiency' => 0.85,
            'c_rate_charge'         => 0.15,
            'c_rate_discharge'      => 0.25,
            'rated_cycle_life'      => 800,
            'calendar_life_years'   => 8,
            'degradation_per_year'  => 0.035,
        ],
        'lithium_lfp' => [
            'label'                 => 'Lithium-Ion (LFP / LiFePO4)',
            'depth_of_discharge'    => 0.90,
            'round_trip_efficiency' => 0.95,
            'c_rate_charge'         => 0.50,
            'c_rate_discharge'      => 1.00,
            'rated_cycle_life'      => 4000,
            'calendar_life_years'   => 15,
            'degradation_per_year'  => 0.02,
        ],
        'lithium_nmc' => [
            'label'                 => 'Lithium-Ion (NMC)',
            'depth_of_discharge'    => 0.80,
            'round_trip_efficiency' => 0.93,
            'c_rate_charge'         => 0.50,
            'c_rate_discharge'      => 1.00,
            'rated_cycle_life'      => 2500,
            'calendar_life_years'   => 10,
            'degradation_per_year'  => 0.025,
        ],
    ];

    public static function all(): array
    {
        return self::$presets;
    }

    public static function getDefaults(string $chemistry): ?array
    {
        return self::$presets[$chemistry] ?? null;
    }

    public static function isValid(string $chemistry): bool
    {
        return isset(self::$presets[$chemistry]);
    }
}
