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
        Schema::table('solar_systems', function (Blueprint $table) {
            $table->decimal('installation_cost', 15, 2)->nullable()->after('notes');
            $table->decimal('annual_maintenance_cost', 12, 2)->nullable()->after('installation_cost');
            $table->unsignedSmallInteger('panel_lifetime_years')->default(25)->after('annual_maintenance_cost');
        });
    }

    public function down(): void
    {
        Schema::table('solar_systems', function (Blueprint $table) {
            $table->dropColumn(['installation_cost', 'annual_maintenance_cost', 'panel_lifetime_years']);
        });
    }
};
