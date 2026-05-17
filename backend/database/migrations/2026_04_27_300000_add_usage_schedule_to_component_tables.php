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
                $table->string('usage_season',      10)->default('all')->after('needs_socket');
                $table->string('usage_day_type',    10)->default('all')->after('usage_season');
                $table->string('usage_time_of_day', 10)->default('all')->after('usage_day_type');
            });
        }

        Schema::table('component_types', function (Blueprint $table) {
            $table->string('default_usage_season',      10)->nullable()->after('default_needs_socket');
            $table->string('default_usage_day_type',    10)->nullable()->after('default_usage_season');
            $table->string('default_usage_time_of_day', 10)->nullable()->after('default_usage_day_type');
        });
    }

    public function down(): void
    {
        foreach (['project_components', 'building_components', 'floor_components', 'room_components'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['usage_season', 'usage_day_type', 'usage_time_of_day']);
            });
        }

        Schema::table('component_types', function (Blueprint $table) {
            $table->dropColumn(['default_usage_season', 'default_usage_day_type', 'default_usage_time_of_day']);
        });
    }
};
