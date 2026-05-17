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
        $tables = ['project_components', 'building_components', 'floor_components', 'room_components'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('phases')->default('1phase')->after('power');       // '1phase' | '3phase'
                $t->decimal('power_factor', 4, 2)->default(1.00)->after('phases'); // 0.01 – 1.00
            });
        }
    }

    public function down(): void
    {
        $tables = ['project_components', 'building_components', 'floor_components', 'room_components'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['phases', 'power_factor']);
            });
        }
    }
};
