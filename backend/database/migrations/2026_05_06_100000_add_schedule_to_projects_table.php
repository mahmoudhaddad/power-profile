<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('work_days')->nullable()->after('generator_power');
            $table->json('work_time_intervals')->nullable()->after('work_days');
            $table->string('working_season', 10)->default('all')->after('work_time_intervals');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['work_days', 'work_time_intervals', 'working_season']);
        });
    }
};
