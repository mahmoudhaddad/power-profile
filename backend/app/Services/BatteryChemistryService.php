<?php

namespace App\Services;

class BatteryChemistryService
{
    // Default DoD and RTE for any chemistry key not listed in $presets.
    // Applied by Battery::getAgeFactorAttribute() when chemistry is unknown.
    public const DEFAULT_DOD = 0.80; // ⚠ tunable
    public const DEFAULT_RTE = 0.90; // ⚠ tunable

    private static array $presets = [
        'lead_acid_flooded' => [
            'label'                 => 'Lead-Acid (Flooded)',
            'depth_of_discharge'    => 0.50,  // ⚠ tunable — flooded lead-acid: 50 % usable
            'round_trip_efficiency' => 0.82,  // ⚠ tunable — flooded ~80-85 %; 0.82 midpoint
            'c_rate_charge'         => 0.10,
            'c_rate_discharge'      => 0.20,
            'rated_cycle_life'      => 500,
            'calendar_life_years'   => 5,
            'degradation_per_year'  => 0.05,  // ⚠ tunable — linear % capacity loss per year
        ],
        'lead_acid_agm' => [
            'label'                 => 'Lead-Acid (AGM)',
            'depth_of_discharge'    => 0.70,  // ⚠ tunable — AGM deep-cycle rated to 70-80 % DoD
            'round_trip_efficiency' => 0.82,  // ⚠ tunable — AGM ~80-85 %; 0.82 midpoint
            'c_rate_charge'         => 0.20,
            'c_rate_discharge'      => 0.30,
            'rated_cycle_life'      => 700,
            'calendar_life_years'   => 7,
            'degradation_per_year'  => 0.04,  // ⚠ tunable — linear % capacity loss per year
        ],
        'lead_acid_gel' => [
            'label'                 => 'Lead-Acid (Gel)',
            'depth_of_discharge'    => 0.80,  // ⚠ tunable — gel deep-cycle rated to 80 % DoD
            'round_trip_efficiency' => 0.82,  // ⚠ tunable — gel ~80-85 %; 0.82 midpoint
            'c_rate_charge'         => 0.15,
            'c_rate_discharge'      => 0.25,
            'rated_cycle_life'      => 800,
            'calendar_life_years'   => 8,
            'degradation_per_year'  => 0.035, // ⚠ tunable — linear % capacity loss per year
        ],
        'lithium_lfp' => [
            'label'                 => 'Lithium-Ion (LFP / LiFePO4)',
            'depth_of_discharge'    => 0.85,  // ⚠ tunable — LFP: 85 % usable (not 90: buffer for longevity)
            'round_trip_efficiency' => 0.92,  // ⚠ tunable — LFP ~90-95 %; 0.92 conservative
            'c_rate_charge'         => 0.50,
            'c_rate_discharge'      => 1.00,
            'rated_cycle_life'      => 4000,
            'calendar_life_years'   => 15,
            'degradation_per_year'  => 0.02,  // ⚠ tunable — linear % capacity loss per year (~2-3 % for LFP)
        ],
        'lithium_nmc' => [
            'label'                 => 'Lithium-Ion (NMC)',
            'depth_of_discharge'    => 0.80,  // ⚠ tunable — NMC: 80 % usable
            'round_trip_efficiency' => 0.93,  // ⚠ tunable — NMC ~90-95 %; 0.93
            'c_rate_charge'         => 0.50,
            'c_rate_discharge'      => 1.00,
            'rated_cycle_life'      => 2500,
            'calendar_life_years'   => 10,
            'degradation_per_year'  => 0.025, // ⚠ tunable — linear % capacity loss per year
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
