<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generator_lines', function (Blueprint $table) {
            // Fuel consumed at idle / no electrical load (L/hr).
            // Typical diesel: 25–33 % of full-load fuel consumption.
            // Used in the affine fuel model: F(P) = F₀ + (F_rated − F₀) × P/P_rated
            $table->decimal('no_load_fuel_lph', 8, 4)->nullable()->after('fuel_consumption_lph');

            // Minimum recommended load as % of rated power.
            // Below this threshold diesel engines risk wet-stacking (unburned fuel /
            // carbon build-up in exhaust) and voided manufacturer warranty.
            // ISO 8528 / most OEM manuals: 25–30 %.
            $table->unsignedTinyInteger('min_load_pct')->default(30)->after('no_load_fuel_lph');

            // Load % at which specific fuel consumption (L/kWh) is lowest.
            // Peak efficiency for most diesel gensets: 70–80 % of rated capacity.
            $table->unsignedTinyInteger('optimal_load_pct')->default(75)->after('min_load_pct');
        });
    }

    public function down(): void
    {
        Schema::table('generator_lines', function (Blueprint $table) {
            $table->dropColumn(['no_load_fuel_lph', 'min_load_pct', 'optimal_load_pct']);
        });
    }
};
