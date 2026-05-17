<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('working_season');
            $table->json('working_season_intervals')->nullable()->after('work_time_intervals');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('working_season_intervals');
            $table->string('working_season', 10)->default('all')->after('work_time_intervals');
        });
    }
};
