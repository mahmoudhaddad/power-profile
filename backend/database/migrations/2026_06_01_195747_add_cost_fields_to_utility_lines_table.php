<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('utility_lines', function (Blueprint $table) {
            $table->decimal('tariff_per_kwh', 10, 4)->nullable()->after('phases');
            $table->decimal('peak_tariff_per_kwh', 10, 4)->nullable()->after('tariff_per_kwh');
            $table->unsignedTinyInteger('peak_hours_start')->nullable()->after('peak_tariff_per_kwh');
            $table->unsignedTinyInteger('peak_hours_end')->nullable()->after('peak_hours_start');
        });
    }

    public function down(): void
    {
        Schema::table('utility_lines', function (Blueprint $table) {
            $table->dropColumn(['tariff_per_kwh', 'peak_tariff_per_kwh', 'peak_hours_start', 'peak_hours_end']);
        });
    }
};
