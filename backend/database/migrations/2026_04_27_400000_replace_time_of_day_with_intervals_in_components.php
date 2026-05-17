<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['project_components', 'building_components', 'floor_components', 'room_components'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('usage_time_of_day');
                $table->json('usage_time_intervals')->nullable()->after('usage_day_type');
            });
        }

        Schema::table('component_types', function (Blueprint $table) {
            $table->dropColumn('default_usage_time_of_day');
            $table->json('default_usage_time_intervals')->nullable()->after('default_usage_day_type');
        });
    }

    public function down(): void
    {
        foreach (['project_components', 'building_components', 'floor_components', 'room_components'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('usage_time_intervals');
                $table->string('usage_time_of_day', 10)->default('all')->after('usage_day_type');
            });
        }

        Schema::table('component_types', function (Blueprint $table) {
            $table->dropColumn('default_usage_time_intervals');
            $table->string('default_usage_time_of_day', 10)->nullable()->after('default_usage_day_type');
        });
    }
};
