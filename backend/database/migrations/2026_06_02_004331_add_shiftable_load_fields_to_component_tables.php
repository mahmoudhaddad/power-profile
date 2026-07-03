<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'room_components',
        'floor_components',
        'building_components',
        'project_components',
    ];

    private const DROP_COLS = [
        'load_flexibility',
        'required_run_hours',
        'earliest_start_hour',
        'latest_end_hour',
        'min_continuous_run',
        'max_interruptions',
        'curtail_min_pct',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                // 'fixed' | 'shiftable' | 'curtailable'
                $table->string('load_flexibility', 20)->default('fixed')->after('priority');
                // Shiftable-only fields
                $table->unsignedTinyInteger('required_run_hours')->nullable()->after('load_flexibility');
                $table->unsignedTinyInteger('earliest_start_hour')->nullable()->after('required_run_hours');
                $table->unsignedTinyInteger('latest_end_hour')->nullable()->after('earliest_start_hour');
                $table->unsignedTinyInteger('min_continuous_run')->nullable()->after('latest_end_hour');
                $table->unsignedTinyInteger('max_interruptions')->nullable()->after('min_continuous_run');
                // Curtailable-only field (0-100 %)
                $table->unsignedTinyInteger('curtail_min_pct')->nullable()->after('max_interruptions');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                $table->dropColumn(self::DROP_COLS);
            });
        }
    }
};
