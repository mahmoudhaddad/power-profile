<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['building_components', 'floor_components', 'room_components'] as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                $table->char('phase', 1)->nullable()->after('phases');
            });
        }
    }

    public function down(): void
    {
        foreach (['building_components', 'floor_components', 'room_components'] as $tbl) {
            Schema::table($tbl, function (Blueprint $table) {
                $table->dropColumn('phase');
            });
        }
    }
};
