<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['room_components', 'floor_components', 'building_components', 'project_components'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('group_name')->nullable()->after('quantity');
            });
        }
    }

    public function down(): void
    {
        foreach (['room_components', 'floor_components', 'building_components', 'project_components'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('group_name');
            });
        }
    }
};
