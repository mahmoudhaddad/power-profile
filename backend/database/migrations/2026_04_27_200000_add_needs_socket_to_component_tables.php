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
                $table->boolean('needs_socket')->default(false)->after('quantity');
            });
        }

        Schema::table('component_types', function (Blueprint $table) {
            $table->boolean('default_needs_socket')->nullable()->after('default_power_factor');
        });
    }

    public function down(): void
    {
        foreach (['project_components', 'building_components', 'floor_components', 'room_components'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('needs_socket');
            });
        }

        Schema::table('component_types', function (Blueprint $table) {
            $table->dropColumn('default_needs_socket');
        });
    }
};
