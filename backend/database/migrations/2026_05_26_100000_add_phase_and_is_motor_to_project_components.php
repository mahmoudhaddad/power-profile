<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_components', function (Blueprint $table) {
            $table->char('phase', 1)->nullable()->after('phases');
        });
    }

    public function down(): void
    {
        Schema::table('project_components', function (Blueprint $table) {
            $table->dropColumn('phase');
        });
    }
};
