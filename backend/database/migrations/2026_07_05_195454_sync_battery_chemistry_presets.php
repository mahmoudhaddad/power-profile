<?php

use App\Services\BatteryChemistryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-apply current chemistry presets to every existing battery row.
 *
 * When presets in BatteryChemistryService change (e.g. LFP DoD 0.90→0.85,
 * all lead-acid RTE →0.82), existing batteries keep their stale stored values
 * because those are set once at creation time.  This migration walks every row,
 * looks up the live preset for its chemistry, and overwrites the five operating
 * parameters with the current values.
 *
 * Fields updated per chemistry:
 *   depth_of_discharge    — usable fraction of nominal capacity
 *   round_trip_efficiency — one-cycle Wh-in / Wh-out
 *   c_rate_charge         — max charge power as fraction of nominal kWh
 *   c_rate_discharge      — max discharge power as fraction of nominal kWh
 *   rated_cycle_life      — expected full-cycle lifetime (informational)
 *
 * Batteries whose chemistry key is not found in the presets are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $presets = BatteryChemistryService::all();

        foreach ($presets as $chemistry => $defaults) {
            DB::table('batteries')
                ->where('chemistry', $chemistry)
                ->update([
                    'depth_of_discharge'    => $defaults['depth_of_discharge'],
                    'round_trip_efficiency' => $defaults['round_trip_efficiency'],
                    'c_rate_charge'         => $defaults['c_rate_charge'],
                    'c_rate_discharge'      => $defaults['c_rate_discharge'],
                    'rated_cycle_life'      => $defaults['rated_cycle_life'],
                    'updated_at'            => now(),
                ]);
        }
    }

    public function down(): void
    {
        // No safe rollback: individual pre-migration values are not recorded.
        // Restore from a database snapshot if needed.
    }
};
